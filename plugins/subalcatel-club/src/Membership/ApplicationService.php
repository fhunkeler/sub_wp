<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

use RuntimeException;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Policy\EligibilityPolicy;
use Subalcatel\Club\Support\Audit;

/**
 * Cycle de vie d'un dossier d'adhésion.
 *
 * Aucun écran n'écrit en base directement : tout passe par ce service, qui
 * vérifie les droits, recalcule le prix au serveur, fige les lignes et
 * journalise. C'est ce qui garantit qu'une même règle ne s'écrit qu'une fois.
 */
final class ApplicationService
{
    public const STATUS_DRAFT             = 'draft';
    public const STATUS_SUBMITTED         = 'submitted';
    public const STATUS_AWAITING_PAYMENT  = 'awaiting_payment';
    public const STATUS_PAYMENT_CONFIRMED = 'payment_confirmed';
    public const STATUS_ACTIVE            = 'active';
    public const STATUS_REFUSED           = 'refused';
    public const STATUS_CANCELLED         = 'cancelled';

    /**
     * Les états dans lesquels un dossier se corrige encore.
     *
     * La limite est l'activation, pas le paiement : une erreur de saisie se
     * découvre le plus souvent quand le chèque arrive et que son montant ne
     * tombe pas juste. Passé l'activation, le dossier a produit une licence et
     * des droits d'emprunt — il ne se réécrit plus, il s'annule.
     *
     * @var list<string>
     */
    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_PAYMENT_CONFIRMED,
    ];

    private string $prefix;

    public function __construct(
        private readonly CampaignRepository $campaigns = new CampaignRepository(),
        private readonly PricingEngine $pricing = new PricingEngine(),
    ) {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'sub_';
    }

    /**
     * Soumet un dossier : recalcule le prix, fige les lignes, ouvre les droits.
     *
     * @param array<string, string|list<string>> $answers
     * @param string $paymentMethod Mode de règlement choisi par l'adhérent.
     */
    public function submit(
        int $userId,
        int $campaignId,
        string $planSlug,
        array $answers,
        string $paymentMethod,
    ): int {
        global $wpdb;

        // Première porte : le bureau doit avoir validé le compte. Laisser un
        // dossier arriver avant cette validation reviendrait à instruire des
        // demandes de gens dont on ne sait rien.
        $account = (new EligibilityPolicy())->hasApprovedAccount($userId);

        if (!$account->allowed) {
            throw new RuntimeException($account->reason);
        }

        $plan = $this->campaigns->planBySlug($campaignId, $planSlug);
        if ($plan === null) {
            throw new RuntimeException('Plan inconnu pour cette campagne.');
        }

        $campaign = $this->campaignRow($campaignId);
        $options  = $this->campaigns->options($campaignId);
        $rules    = $this->campaigns->discountRules($campaignId);

        if (!PaymentMethods::isOffered($paymentMethod)) {
            throw new RuntimeException('Choisissez un mode de règlement.');
        }

        // Options automatiques appliquées, cases décochées ramenées à « non »,
        // réponses étrangères au plan écartées : ce qui suit — contrôle des
        // obligatoires, calcul, archivage — travaille sur le même jeu.
        $answers = PricingEngine::resolveAnswers($plan, $answers, $options);

        // L'état civil et les coordonnées valent réponse obligatoire : sans eux,
        // le dossier ne produit pas de licence, et le bureau court après.
        $missing = ApplicantIdentity::missing($userId)
            + $this->missingRequired($plan, $options, $answers);

        if ($missing !== []) {
            throw new IncompleteApplication($missing);
        }

        // Le prix qui fait foi est recalculé ici, jamais celui posté par le
        // navigateur.
        $quote = $this->pricing->calculate($plan, $answers, $options, $rules);

        $wpdb->insert("{$this->prefix}applications", [
            'reference'      => $this->nextReference(),
            'user_id'        => $userId,
            'campaign_id'    => $campaignId,
            'plan_id'        => $plan->id,
            'status'         => self::STATUS_AWAITING_PAYMENT,
            'total_amount'   => $quote->total(),
            'payment_method' => $paymentMethod,
            'valid_from'     => $campaign['valid_from'],
            'valid_until'    => $campaign['valid_until'],
            'submitted_at'   => current_time('mysql'),
        ]);

        $applicationId = (int) $wpdb->insert_id;

        $this->freezeLines($applicationId, $quote);
        $this->storeAnswers($applicationId, $answers);
        $this->recordValidation($applicationId, 'submission', 'submitted', $userId);

        Audit::log('membership.submitted', 'application', $applicationId, [
            'plan'  => $plan->slug,
            'total' => $quote->total(),
        ], $userId);

        Mailer::toUser(EmailTemplates::MEMBERSHIP_SUBMITTED, $userId, [
            'reference'  => $this->find($applicationId)['reference'] ?? '',
            'montant'    => number_format($quote->total(), 2, ',', ' ') . ' €',
            'formule'    => $plan->title,
            'reglement'  => PaymentMethods::label($paymentMethod),
            'consignes'  => PaymentMethods::instructions($paymentMethod),
        ], ['entity_type' => 'application', 'entity_id' => $applicationId]);

        return $applicationId;
    }

    /**
     * Corrige un dossier déposé, tant qu'il n'est pas activé.
     *
     * Une adhésion se remplit une fois par an, sur un formulaire qu'on découvre
     * à chaque saison : la case oubliée est la règle, pas l'exception. Jusqu'ici
     * le bureau n'avait que deux gestes — refuser le dossier et faire tout
     * retaper, ou corriger le montant à la main sans que les lignes suivent.
     * Ni l'un ni l'autre ne laissait une comptabilité juste.
     *
     * La correction repasse donc par le même chemin que la soumission : mêmes
     * règles de visibilité, même contrôle des réponses obligatoires, même
     * recalcul au serveur, mêmes lignes figées. Ce qui change est seulement qui
     * tient le clavier — et cela, le journal le retient.
     *
     * Le prix est recalculé sur la campagne DU DOSSIER, jamais sur celle qui se
     * trouve ouverte aujourd'hui : corriger en janvier une adhésion déposée en
     * septembre ne doit pas lui appliquer les tarifs de la saison suivante.
     *
     * @param array<string, string|list<string>> $answers
     * @return float Le nouveau total du dossier.
     */
    public function amend(
        int $applicationId,
        int $actorId,
        string $planSlug,
        array $answers,
        string $paymentMethod,
        string $comment = '',
    ): float {
        global $wpdb;

        if (!user_can($actorId, 'sub_manage_memberships')) {
            throw new RuntimeException('Droit de gestion des adhésions requis.');
        }

        $application = $this->find($applicationId);
        if ($application === null) {
            throw new RuntimeException('Dossier introuvable.');
        }

        if (!self::isEditable((string) $application['status'])) {
            throw new RuntimeException(
                'Ce dossier n’est plus modifiable : une adhésion active ou close ne se réécrit pas.'
            );
        }

        $campaignId = (int) $application['campaign_id'];

        $plan = $this->campaigns->planBySlug($campaignId, $planSlug);
        if ($plan === null) {
            throw new RuntimeException('Plan inconnu pour cette campagne.');
        }

        if (!PaymentMethods::isOffered($paymentMethod)) {
            throw new RuntimeException('Choisissez un mode de règlement.');
        }

        $options = $this->campaigns->options($campaignId);
        $rules   = $this->campaigns->discountRules($campaignId);
        $answers = PricingEngine::resolveAnswers($plan, $answers, $options);

        // L'état civil n'est pas revérifié ici : il vit sur la fiche du membre,
        // se corrige depuis l'annuaire, et le dossier a déjà passé ce contrôle
        // à son dépôt. Les réponses obligatoires, elles, appartiennent au
        // dossier — une correction ne doit pas pouvoir en retirer une.
        $missing = $this->missingRequired($plan, $options, $answers);

        if ($missing !== []) {
            throw new IncompleteApplication($missing);
        }

        $quote    = $this->pricing->calculate($plan, $answers, $options, $rules);
        $previous = (float) $application['total_amount'];

        $wpdb->update("{$this->prefix}applications", [
            'plan_id'        => $plan->id,
            'total_amount'   => $quote->total(),
            'payment_method' => $paymentMethod,
            'updated_at'     => current_time('mysql'),
        ], ['id' => $applicationId]);

        // Les lignes sont remplacées en bloc : une correction n'ajoute pas une
        // ligne de régularisation, elle redit ce que le dossier contient.
        $wpdb->delete("{$this->prefix}application_lines", ['application_id' => $applicationId]);
        $this->freezeLines($applicationId, $quote);
        $this->storeAnswers($applicationId, $answers);

        $this->recordValidation($applicationId, 'correction', 'amended', $actorId, $comment);

        Audit::log('membership.amended', 'application', $applicationId, [
            'plan'  => $plan->slug,
            'from'  => $previous,
            'to'    => $quote->total(),
        ], $actorId);

        // Le membre est prévenu dès que le montant bouge : il a peut-être déjà
        // posté son chèque, et découvrir l'écart à l'encaissement est le plus
        // sûr moyen d'une relance pénible. Une correction sans effet sur le
        // prix — un droit d'emprunt rectifié — ne mérite pas de courriel.
        if ($application['user_id'] !== null && abs($quote->total() - $previous) >= 0.005) {
            Mailer::toUser(EmailTemplates::MEMBERSHIP_AMENDED, (int) $application['user_id'], [
                'reference'      => (string) $application['reference'],
                'formule'        => $plan->title,
                'ancien_montant' => number_format($previous, 2, ',', ' ') . ' €',
                'montant'        => number_format($quote->total(), 2, ',', ' ') . ' €',
                'motif'          => $comment !== '' ? $comment : 'Correction du dossier par le bureau.',
            ], ['entity_type' => 'application', 'entity_id' => $applicationId, 'sender_id' => $actorId]);
        }

        return $quote->total();
    }

    public static function isEditable(string $status): bool
    {
        return in_array($status, self::EDITABLE_STATUSES, true);
    }

    /**
     * Somme réellement encaissée sur un dossier.
     *
     * Elle ne se déduit pas du statut : une correction peut changer le montant
     * dû après que la trésorerie a enregistré le règlement, et c'est justement
     * cet écart qu'il faut pouvoir montrer.
     */
    public function paidAmount(int $applicationId): float
    {
        global $wpdb;

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$this->prefix}payments
             WHERE application_id = %d AND status = 'received'",
            $applicationId
        ));
    }

    /**
     * Enregistre un paiement et fait avancer le dossier.
     *
     * En v1 le paiement est saisi à la main par la trésorerie — chèque ou
     * HelloAsso encaissé hors du site (cf. §7 de la proposition).
     */
    public function recordPayment(
        int $applicationId,
        float $amount,
        string $method,
        ?string $receivedOn,
        int $actorId,
        string $reference = '',
    ): void {
        global $wpdb;

        if (!user_can($actorId, 'sub_validate_membership_treasury')) {
            throw new RuntimeException('Droit de validation trésorerie requis.');
        }

        $application = $this->find($applicationId);
        if ($application === null) {
            throw new RuntimeException('Dossier introuvable.');
        }

        if (!PaymentMethods::isOffered($method)) {
            throw new RuntimeException('Mode de règlement inconnu.');
        }

        $wpdb->insert("{$this->prefix}payments", [
            'application_id' => $applicationId,
            'user_id'        => (int) $application['user_id'],
            'amount'         => $amount,
            'method'         => $method,
            'status'         => 'received',
            'reference'      => $reference,
            'received_on'    => $receivedOn ?: current_time('Y-m-d'),
            'recorded_by'    => $actorId,
        ]);

        $this->setStatus($applicationId, self::STATUS_PAYMENT_CONFIRMED);
        $this->recordValidation($applicationId, 'treasury', 'confirmed', $actorId);

        Audit::log('membership.payment_recorded', 'application', $applicationId, [
            'amount' => $amount,
            'method' => $method,
        ], $actorId);

        Mailer::toUser(EmailTemplates::MEMBERSHIP_PAID, (int) $application['user_id'], [
            'reference' => (string) $application['reference'],
            'montant'   => number_format($amount, 2, ',', ' ') . ' €',
            'mode'      => $method,
        ], ['entity_type' => 'application', 'entity_id' => $applicationId, 'sender_id' => $actorId]);
    }

    /**
     * Validation secrétariat : dernière étape avant activation.
     */
    public function validateSecretariat(int $applicationId, int $actorId, string $comment = ''): void
    {
        if (!user_can($actorId, 'sub_validate_membership_secretariat')) {
            throw new RuntimeException('Droit de validation secrétariat requis.');
        }

        $application = $this->find($applicationId);
        if ($application === null) {
            throw new RuntimeException('Dossier introuvable.');
        }

        if ($application['status'] !== self::STATUS_PAYMENT_CONFIRMED) {
            throw new RuntimeException('Le paiement doit être confirmé avant validation.');
        }

        $this->setStatus($applicationId, self::STATUS_ACTIVE, activated: true);
        $this->recordValidation($applicationId, 'secretariat', 'approved', $actorId, $comment);
        $this->applyMembership($application);

        Audit::log('membership.activated', 'application', $applicationId, [], $actorId);

        Mailer::toUser(EmailTemplates::MEMBERSHIP_ACTIVATED, (int) $application['user_id'], [
            'fin_validite' => self::frDate((string) $application['valid_until']),
        ], ['entity_type' => 'application', 'entity_id' => $applicationId, 'sender_id' => $actorId]);
    }

    public function refuse(int $applicationId, int $actorId, string $reason): void
    {
        if (!user_can($actorId, 'sub_validate_membership_secretariat')) {
            throw new RuntimeException('Droit de validation secrétariat requis.');
        }

        $this->setStatus($applicationId, self::STATUS_REFUSED);
        $this->recordValidation($applicationId, 'secretariat', 'refused', $actorId, $reason);

        Audit::log('membership.refused', 'application', $applicationId, ['reason' => $reason], $actorId);

        $application = $this->find($applicationId);

        if ($application !== null) {
            Mailer::toUser(EmailTemplates::MEMBERSHIP_REFUSED, (int) $application['user_id'], [
                'reference' => (string) $application['reference'],
                'motif'     => $reason,
            ], ['entity_type' => 'application', 'entity_id' => $applicationId, 'sender_id' => $actorId]);
        }
    }

    /**
     * Répercute une adhésion active sur le compte : rôle, validité, droits.
     *
     * @param array<string, mixed> $application
     */
    private function applyMembership(array $application): void
    {
        $userId = (int) $application['user_id'];

        update_user_meta($userId, 'sub_membership_valid_until', $application['valid_until']);
        update_user_meta($userId, 'sub_membership_application_id', (int) $application['id']);
        update_user_meta($userId, 'sub_lending_rights', $this->grantsFor((int) $application['id']));

        $user = get_userdata($userId);
        if ($user && !in_array('sub_member', (array) $user->roles, true)) {
            $user->add_role('sub_member');
        }
    }

    /**
     * Droits d'emprunt ouverts par les options retenues dans un dossier.
     *
     * @return list<string>
     */
    private function grantsFor(int $applicationId): array
    {
        $application = $this->find($applicationId);
        if ($application === null) {
            return [];
        }

        $answers = $this->answers($applicationId);
        $grants  = [];

        foreach ($this->campaigns->options((int) $application['campaign_id']) as $option) {
            $answer = $answers[$option->name] ?? null;

            if ($answer === null || !$option->isVisible($answers)) {
                continue;
            }

            // C'est le choix retenu qui dit s'il ouvre un droit : « non » n'ouvre
            // rien, et le bloc de l'encadrant en ouvre un sans rien coûter.
            $grants = array_merge($grants, $option->grantsFor($answer));
        }

        return array_values(array_unique($grants));
    }

    /**
     * @param list<Option> $options
     * @param array<string, string|list<string>> $answers
     * @return array<string, string> nom technique => libellé
     */
    private function missingRequired(Plan $plan, array $options, array $answers): array
    {
        $missing = [];

        foreach ($options as $option) {
            if (!$option->isRequired || !$option->appliesToPlan($plan->slug) || !$option->isVisible($answers)) {
                continue;
            }

            $answer = $answers[$option->name] ?? null;

            if ($answer === null || $answer === '' || $answer === []) {
                $missing[$option->name] = $option->label;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $applicationId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->prefix}applications WHERE id = %d", $applicationId),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(int $applicationId): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->prefix}application_lines
                 WHERE application_id = %d ORDER BY ordering ASC",
                $applicationId
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function answers(int $applicationId): array
    {
        $raw = get_option("sub_application_answers_{$applicationId}", []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Fige le détail tarifaire d'un dossier.
     *
     * Changer un tarif l'an prochain ne doit pas réécrire la comptabilité de
     * cette année : ces lignes sont une copie, pas une jointure.
     */
    private function freezeLines(int $applicationId, Quote $quote): void
    {
        global $wpdb;

        $ordering = 0;

        foreach ($quote->lines as $line) {
            $wpdb->insert("{$this->prefix}application_lines", [
                'application_id' => $applicationId,
                'line_type'      => $line->type,
                'source_name'    => $line->sourceName,
                'label'          => $line->label,
                'value_label'    => $line->valueLabel,
                'amount'         => $line->amount,
                'ordering'       => $ordering++,
            ]);
        }
    }

    /**
     * @param array<string, string|list<string>> $answers
     */
    private function storeAnswers(int $applicationId, array $answers): void
    {
        update_option("sub_application_answers_{$applicationId}", $answers, false);
    }

    private function setStatus(int $applicationId, string $status, bool $activated = false): void
    {
        global $wpdb;

        $data = ['status' => $status, 'updated_at' => current_time('mysql')];
        if ($activated) {
            $data['activated_at'] = current_time('mysql');
        }

        $wpdb->update("{$this->prefix}applications", $data, ['id' => $applicationId]);
    }

    private function recordValidation(
        int $applicationId,
        string $step,
        string $decision,
        int $actorId,
        string $comment = '',
    ): void {
        global $wpdb;

        $wpdb->insert("{$this->prefix}validations", [
            'application_id' => $applicationId,
            'step'           => $step,
            'decision'       => $decision,
            'actor_id'       => $actorId,
            'comment'        => $comment,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignRow(int $campaignId): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->prefix}campaigns WHERE id = %d", $campaignId),
            ARRAY_A
        );

        if (!$row) {
            throw new RuntimeException('Campagne introuvable.');
        }

        return $row;
    }

    public static function frDate(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '') {
            return '';
        }

        $ts = strtotime($isoDate);

        return $ts === false ? $isoDate : wp_date('j F Y', $ts);
    }

    private function nextReference(): string
    {
        return sprintf('ADH-%s-%s', date('Y'), strtoupper(wp_generate_password(6, false, false)));
    }
}
