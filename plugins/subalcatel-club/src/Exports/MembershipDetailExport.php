<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\Option;

/**
 * Le détail d'un dossier d'adhésion, colonne par colonne, pour le bureau.
 *
 * Demandé par le président en septembre 2026 : les exports existants
 * (« Liste des adhérents », « Affiliation FFESSM ») répondent chacun à un
 * usage précis, mais aucun ne rassemble ce que le bureau suit dossier par
 * dossier — les options souscrites, la remise Nokia et le mode de paiement,
 * qu'aucun des deux autres n'expose. Une ligne par dossier plutôt qu'une ligne
 * par membre : ces montants sont propres à la campagne, pas à la personne, et
 * changent d'une saison à l'autre pour le même adhérent.
 *
 * Une ligne = un dossier de la campagne en cours (ouverte, sinon la plus
 * récente) — c'est ainsi que le club produisait déjà ses extraits saison par
 * saison. Un dossier encore brouillon ne compte pas : rien n'a été soumis. Un
 * dossier refusé ou annulé non plus : ce ne sont pas des adhésions.
 */
final class MembershipDetailExport extends Export
{
    /**
     * Modes de paiement connus, comme dans {@see PaymentsExport}.
     *
     * @var array<string, string>
     */
    private const PAYMENT_METHODS = [
        'cheque'    => 'Chèque',
        'helloasso' => 'HelloAsso',
        'virement'  => 'Virement',
        'especes'   => 'Espèces',
    ];

    /**
     * Statuts qui constituent une adhésion réelle : au moins soumise, jamais
     * refusée ni annulée. Un brouillon n'a pas de réponses figées à montrer.
     *
     * @var list<string>
     */
    private const REPORTED_STATUSES = [
        ApplicationService::STATUS_AWAITING_PAYMENT,
        ApplicationService::STATUS_PAYMENT_CONFIRMED,
        ApplicationService::STATUS_ACTIVE,
    ];

    public function key(): string
    {
        return 'membership-detail';
    }

    public function label(): string
    {
        return 'Détail des adhésions';
    }

    public function description(): string
    {
        return 'Une ligne par dossier de la saison choisie : licence, adhésion, '
            . 'options souscrites, remises et paiement — la vue complète du bureau.';
    }

    public function capability(): string
    {
        return 'sub_export_members';
    }

    public function columns(): array
    {
        return [
            'Licence FFESSM',
            'Date d’adhésion',
            'Username',
            'Prénom',
            'Nom',
            'N° carte ASAC',
            'Type Adhésion',
            'Origine adhésion',
            'Moins-value Licence',
            'Moins-value Nokia',
            'Assurance',
            'Piscine',
            'Bloc',
            'Stab',
            'Détendeur',
            'Carte niveau',
            'Supp inscription tardive',
            'Type paiement',
            'Montant cotisation site',
        ];
    }

    /**
     * Les saisons proposées au choix, de la plus récente à la plus ancienne.
     *
     * Le bureau produisait déjà ses extraits saison par saison, et l'ouverture
     * d'une nouvelle campagne ne doit pas rendre la précédente inaccessible :
     * un trésorier qui boucle l'exercice a besoin de la saison écoulée, pas de
     * celle qui commence.
     *
     * Une saison sans options le dit dans son libellé. C'est le cas de la
     * saison reprise du Joomla : la reprise a rapatrié le montant global, pas
     * le détail des options souscrites. L'extrait sort alors correctement,
     * mais avec ses colonnes d'options vides — mieux vaut l'annoncer ici que
     * le laisser découvrir dans le fichier.
     *
     * @return array<int, string> identifiant => libellé
     */
    public static function campaigns(): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $rows = $wpdb->get_results(
            "SELECT c.id, c.title, COUNT(o.id) AS options_count
             FROM {$p}campaigns c
             LEFT JOIN {$p}options o ON o.campaign_id = c.id
             GROUP BY c.id, c.title, c.valid_from
             ORDER BY c.valid_from DESC, c.id DESC",
            ARRAY_A
        ) ?: [];

        $campaigns = [];

        foreach ($rows as $row) {
            $title = (string) $row['title'];

            if ((int) $row['options_count'] === 0) {
                $title .= ' — détail des options non repris';
            }

            $campaigns[(int) $row['id']] = $title;
        }

        return $campaigns;
    }

    public function rows(array $args = []): array
    {
        $campaignId = isset($args['campaign_id'])
            ? (int) $args['campaign_id']
            : $this->currentCampaignId();

        if ($campaignId === 0) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $optionsByName = [];
        foreach ((new CampaignRepository())->options($campaignId) as $option) {
            $optionsByName[$option->name] = $option;
        }

        $placeholders = implode(',', array_fill(0, count(self::REPORTED_STATUSES), '%s'));

        $applications = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.id, a.user_id, a.total_amount, a.submitted_at, a.activated_at,
                        pl.title AS plan_title
                 FROM {$p}applications a
                 LEFT JOIN {$p}plans pl ON pl.id = a.plan_id
                 WHERE a.campaign_id = %d
                   AND a.status IN ({$placeholders})
                 ORDER BY a.submitted_at ASC, a.id ASC",
                array_merge([$campaignId], self::REPORTED_STATUSES)
            ),
            ARRAY_A
        ) ?: [];

        $service = new ApplicationService();

        return array_map(
            fn (array $application): array => $this->row($application, $optionsByName, $service),
            $applications
        );
    }

    /**
     * @param array<string, mixed> $application
     * @param array<string, Option> $optionsByName
     */
    private function row(array $application, array $optionsByName, ApplicationService $service): array
    {
        $applicationId = (int) $application['id'];
        $userId        = (int) $application['user_id'];
        $user          = $userId > 0 ? get_userdata($userId) : false;

        $answers = $service->answers($applicationId);
        $lines   = $service->lines($applicationId);

        $moinsValueLicence = 0.0;
        $moinsValueNokia   = 0.0;
        $supplementTardif  = 0.0;

        foreach ($lines as $line) {
            $amount = (float) $line['amount'];

            if ($line['source_name'] === 'moins_value_licence') {
                $moinsValueLicence += $amount;
            }

            // La seule remise du club porte aujourd'hui sur l'origine Nokia
            // (cf. DemoSeeder) : une ligne de type « remise » en est donc
            // l'équivalent, sans dépendre d'un nom d'option qui n'existe que
            // sur la règle, pas sur la ligne comptable.
            if ($line['line_type'] === 'discount') {
                $moinsValueNokia += $amount;
            }

            // Pas encore une option du club : si le bureau crée un jour un
            // supplément d'inscription tardive depuis l'écran de campagne,
            // cette colonne le retrouve sans modification de code, du moment
            // que son libellé le dit.
            if (stripos((string) $line['label'], 'tardiv') !== false) {
                $supplementTardif += $amount;
            }
        }

        return [
            $user ? ProfileFields::get($userId, 'licence_number') : '',
            Members::frDate((string) ($application['submitted_at'] ?: $application['activated_at'])),
            $user ? $user->user_login : '',
            $user ? $user->first_name : '',
            $user ? ($user->last_name ?: $user->display_name) : '',
            $user ? ProfileFields::get($userId, 'asac_card') : '',
            (string) ($application['plan_title'] ?? ''),
            $this->answerLabel($optionsByName, $answers, 'origine_adhesion'),
            round($moinsValueLicence, 2),
            round($moinsValueNokia, 2),
            $this->answerLabel($optionsByName, $answers, 'assurance_individuelle'),
            $this->answerLabel($optionsByName, $answers, 'piscine'),
            $this->answerLabel($optionsByName, $answers, 'pret_bloc'),
            $this->answerLabel($optionsByName, $answers, 'pret_gilet'),
            $this->answerLabel($optionsByName, $answers, 'pret_detendeur'),
            $this->answerLabel($optionsByName, $answers, 'carte_niveau'),
            $supplementTardif !== 0.0 ? round($supplementTardif, 2) : '',
            self::PAYMENT_METHODS[$this->latestPaymentMethod($applicationId)] ?? '',
            round((float) $application['total_amount'], 2),
        ];
    }

    /**
     * Traduit une réponse technique en libellé, via les options de la
     * campagne. Une réponse absente — dossier repris sans formulaire, option
     * qui ne s'applique pas à ce plan — reste une cellule vide, jamais un
     * « Non » inventé.
     *
     * @param array<string, Option> $optionsByName
     * @param array<string, string|list<string>> $answers
     */
    private function answerLabel(array $optionsByName, array $answers, string $name): string
    {
        $option = $optionsByName[$name] ?? null;
        $answer = $answers[$name] ?? null;

        if ($option === null || $answer === null || $answer === '' || $answer === []) {
            return '';
        }

        $resolved = $option->resolve($answer);

        if ($resolved === []) {
            return '';
        }

        return implode(', ', array_map(
            static fn (array $pair): string => (string) $pair[0],
            $resolved
        ));
    }

    private function latestPaymentMethod(int $applicationId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT method FROM {$wpdb->prefix}sub_payments
             WHERE application_id = %d
             ORDER BY received_on DESC, id DESC LIMIT 1",
            $applicationId
        ));
    }

    /**
     * Campagne ouverte, sinon la plus récente — comme la grille de tarifs
     * publique. Le bureau produit cet export pour suivre la saison en cours,
     * pas pour reconstituer l'historique.
     */
    private function currentCampaignId(): int
    {
        $campaign = (new CampaignRepository())->campaignToShow();

        return $campaign === null ? 0 : (int) $campaign['campaign']['id'];
    }
}
