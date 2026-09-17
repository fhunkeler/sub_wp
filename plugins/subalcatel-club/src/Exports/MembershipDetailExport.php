<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\Option;
use Subalcatel\Club\Membership\PaymentMethods;

/**
 * Le détail d'un dossier d'adhésion, aux colonnes de l'ancien site.
 *
 * Le bureau a dix ans de tableaux bâtis sur l'extrait OSMembership du Joomla :
 * des tableaux croisés, des formules, des rapprochements bancaires qui
 * désignent leurs colonnes par leur rang. Changer l'ordre ou le nom d'une seule
 * colonne, c'est leur demander de tout refaire une saison de plus. Le président
 * a donc demandé, le 17/09/2026, un extrait « aux colonnes identiques » — et
 * c'est bien la forme de l'ancien fichier qui fait foi ici, jusqu'aux noms
 * techniques `osm_*` et aux colonnes que le nouveau site ne remplit plus.
 *
 * Trois sortes de colonnes coexistent donc :
 *
 * 1. Celles que le nouveau site renseigne — l'essentiel : identité, options
 *    souscrites, montants, règlement.
 * 2. Celles qui n'ont plus d'objet : `osm_Jeune`, le tarif jeune ayant été
 *    retiré en septembre 2026. Vide, et non supprimée : la colonne tient sa
 *    place pour que les suivantes gardent la leur.
 * 3. Celles qui relevaient de la mécanique OSMembership — `tax_amount`,
 *    `published`, `Paiement_messages_*`. Elles gardent la valeur constante
 *    qu'elles avaient dans l'ancien fichier, pour la même raison.
 *
 * Trois colonnes s'ajoutent en fin de ligne, que l'ancien format ne portait
 * pas : la licence FFESSM, le numéro de carte ASAC et le supplément
 * d'inscription tardive. Le président les avait demandées le 08/09/2026, le
 * dernier en « indispensable ». Elles arrivent après la quarante-sixième,
 * jamais entre : les colonnes de l'ancien fichier gardent ainsi leur rang, et
 * un tableau qui s'arrête à `invoice_number` ne voit pas la différence.
 *
 * Une ligne = un dossier de la campagne choisie. Un dossier encore brouillon ne
 * compte pas : rien n'a été soumis. Un dossier refusé ou annulé non plus : ce
 * ne sont pas des adhésions.
 */
final class MembershipDetailExport extends Export
{
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

    /** Ce que l'ancien site inscrivait en `category` pour toute adhésion. */
    private const CATEGORY = 'Adhésion Sub Alcatel';

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
        return 'Une ligne par dossier de la saison choisie, aux colonnes exactes de '
            . 'l’extrait de l’ancien site : identité, options souscrites, montants '
            . 'et règlement. Reprenable tel quel dans les tableaux du bureau. '
            . 'Trois colonnes s’ajoutent à la fin — licence FFESSM, carte ASAC et '
            . 'supplément d’inscription tardive.';
    }

    public function capability(): string
    {
        return 'sub_export_members';
    }

    /**
     * Les 46 colonnes de l'extrait OSMembership dans leur ordre d'origine,
     * puis les trois que le bureau a demandées en plus.
     *
     * L'ordre est la donnée : les tableaux du bureau désignent leurs colonnes
     * par leur rang. Ne rien insérer dans les quarante-six premières, ne rien
     * y retirer — une colonne devenue sans objet sort vide, et ce qui s'ajoute
     * s'ajoute à la fin.
     */
    public function columns(): array
    {
        return [
            'id',
            'category',
            'plan',
            'user_id',
            'username',
            'first_name',
            'last_name',
            'address',
            'zip',
            'city',
            'phone',
            'osm_telephone_professionnel',
            'osm_telephone_mobile',
            'osm_Date_de_naissance',
            'birthcity',
            'birthdepartment',
            'birthcountry',
            'email',
            'osm_Courriel_Secondaire',
            'osm_Piscine',
            'osm_Jeune',
            'osm_Origine_Adhesion',
            'osm_carte_niveau',
            'osm_Niveau_prepare',
            'osm_Pret_Bloc',
            'osm_Pret_Detendeur',
            'osm_Pret_Gilet',
            'osm_Assurance_Individuelle',
            'osm_Moins_Value_Licence_FFESSM_Adulte',
            'osm_Paiement',
            'Paiement_messages_CB',
            'Paiement_messages_Cheque',
            'comment',
            'created_date',
            'payment_date',
            'from_date',
            'to_date',
            'published',
            'amount',
            'tax_amount',
            'discount_amount',
            'gross_amount',
            'payment_method',
            'transaction_id',
            'membership_id',
            'invoice_number',
            // Au-delà de l'ancien format : demandées le 08/09/2026, et sans
            // équivalent OSMembership. D'où les libellés du bureau plutôt que
            // des noms techniques — personne n'a de tableau bâti dessus.
            'Licence FFESSM',
            'N° carte ASAC',
            'Supp inscription tardive',
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
                "SELECT a.id, a.reference, a.user_id, a.total_amount, a.payment_method,
                        a.valid_from, a.valid_until, a.created_at, a.submitted_at, a.activated_at,
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
        $payment = $this->latestPayment($applicationId);

        // Une remise est portée en négatif dans les lignes figées ; l'ancien
        // fichier l'écrivait en valeur absolue, dans une colonne qui ne sait
        // pas dire son signe. On la retourne donc ici, et le total reste le
        // total — `amount` et `gross_amount` étaient déjà nets dans l'extrait
        // d'origine.
        $discount = 0.0;

        // Pas encore une option du club : si le bureau crée un jour un
        // supplément d'inscription tardive depuis l'écran de campagne, la
        // colonne le retrouve sans modification de code, du moment que son
        // libellé le dit.
        $lateFee = 0.0;

        foreach ($service->lines($applicationId) as $line) {
            if ($line['line_type'] === 'discount') {
                $discount += (float) $line['amount'];
            }

            if (stripos((string) $line['label'], 'tardiv') !== false) {
                $lateFee += (float) $line['amount'];
            }
        }

        $total  = round((float) $application['total_amount'], 2);
        $method = (string) ($application['payment_method'] ?? '');

        if ($payment !== null && (string) $payment['method'] !== '') {
            $method = (string) $payment['method'];
        }

        $profile = static fn (string $field): string => $userId > 0
            ? ProfileFields::get($userId, $field)
            : '';

        return [
            $applicationId,
            self::CATEGORY,
            (string) ($application['plan_title'] ?? ''),
            $userId,
            $user ? $user->user_login : '',
            $user ? $user->first_name : '',
            $user ? ($user->last_name ?: $user->display_name) : '',
            $profile('address'),
            $profile('postal_code'),
            $profile('city'),
            $profile('phone'),
            $profile('work_phone'),
            $profile('mobile'),
            $profile('birth_date'),
            $profile('birth_city'),
            $profile('birth_department'),
            $profile('birth_country'),
            $user ? $user->user_email : '',
            $profile('secondary_email'),
            $this->answerLabel($optionsByName, $answers, 'piscine'),
            // Le tarif jeune a été retiré en septembre 2026 : la colonne reste,
            // vide, pour ne pas décaler les suivantes.
            '',
            $this->answerLabel($optionsByName, $answers, 'origine_adhesion'),
            $this->answerLabel($optionsByName, $answers, 'carte_niveau'),
            $this->answerLabel($optionsByName, $answers, 'niveau_prepare'),
            $this->answerLabel($optionsByName, $answers, 'pret_bloc'),
            $this->answerLabel($optionsByName, $answers, 'pret_detendeur'),
            $this->answerLabel($optionsByName, $answers, 'pret_gilet'),
            $this->answerLabel($optionsByName, $answers, 'assurance_individuelle'),
            // La licence déjà détenue se pose en deux options — l'ordinaire et
            // celle des adhérents Nokia, au montant qu'ils ont réellement
            // acquitté. Une seule est visible à la fois, et l'ancien fichier
            // n'avait qu'une colonne : c'est celle qui a répondu qui s'y écrit.
            $this->firstAnswerLabel(
                $optionsByName,
                $answers,
                ['moins_value_licence', 'moins_value_licence_nokia']
            ),
            PaymentMethods::label($method),
            // Messages que l'ancien module affichait à l'adhérent selon son mode
            // de règlement. Le nouveau site les porte dans ses gabarits de
            // courriel, pas dans le dossier : colonnes vides, place tenue.
            '',
            '',
            $this->lastComment($applicationId),
            self::frDateTime((string) $application['created_at']),
            $payment === null ? '' : Members::frDate((string) $payment['received_on']),
            self::frDayBound((string) $application['valid_from'], false),
            self::frDayBound((string) $application['valid_until'], true),
            1,
            $total,
            '0.00',
            number_format(abs($discount), 2, '.', ''),
            $total,
            $method,
            $payment === null ? '' : (string) ($payment['reference'] ?? ''),
            // L'ancien site tenait deux enregistrements — la souscription et
            // l'adhésion qu'elle produisait — et deux identifiants avec eux. Ici
            // c'est le même objet : la colonne reprend donc `id`, plutôt que de
            // sortir vide et de casser les rapprochements qui s'en servent.
            $applicationId,
            (string) $application['reference'],
            $profile('licence_number'),
            $profile('asac_card'),
            $lateFee !== 0.0 ? round($lateFee, 2) : '',
        ];
    }

    /**
     * Traduit une réponse technique en libellé, via les options de la
     * campagne. Une réponse absente — dossier repris sans formulaire, option
     * qui ne s'applique pas à ce plan — reste une cellule vide, jamais un
     * « Non » inventé.
     *
     * Une option que les autres réponses écartent est vide elle aussi, et pour
     * la même raison : une réponse peut rester dans le dossier alors que le
     * formulaire ne posait plus la question — la licence déjà détenue pour un
     * adhérent Nokia, la carte de niveau sans niveau préparé. Le calcul les
     * ignore déjà ; l'extrait doit dire la même chose que la facture, sans quoi
     * le bureau lit une déduction qui n'a jamais été accordée.
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

        if (!$option->isVisible($answers)) {
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

    /**
     * Le libellé de la première option qui a répondu, parmi plusieurs.
     *
     * Sert aux questions posées en plusieurs options dont une seule est visible
     * — la licence déjà détenue, dont le montant diffère selon l'origine de
     * l'adhésion. L'ancien fichier n'avait qu'une colonne pour elles ; il n'en
     * a toujours qu'une.
     *
     * @param array<string, Option> $optionsByName
     * @param array<string, string|list<string>> $answers
     * @param list<string> $names
     */
    private function firstAnswerLabel(array $optionsByName, array $answers, array $names): string
    {
        foreach ($names as $name) {
            $label = $this->answerLabel($optionsByName, $answers, $name);

            if ($label !== '') {
                return $label;
            }
        }

        return '';
    }

    /**
     * Le dernier règlement enregistré sur le dossier, s'il y en a un.
     *
     * @return array<string, mixed>|null
     */
    private function latestPayment(int $applicationId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT method, reference, received_on FROM {$wpdb->prefix}sub_payments
             WHERE application_id = %d
             ORDER BY received_on DESC, id DESC LIMIT 1",
            $applicationId
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Le dernier commentaire porté au dossier par le bureau — un motif de
     * refus, une note de trésorerie. C'est ce que l'ancien fichier mettait en
     * `comment`.
     */
    private function lastComment(int $applicationId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT comment FROM {$wpdb->prefix}sub_validations
             WHERE application_id = %d AND comment <> ''
             ORDER BY created_at DESC, id DESC LIMIT 1",
            $applicationId
        ));
    }

    /**
     * Une borne de journée, comme l'ancien extrait l'écrivait.
     *
     * `valid_from` et `valid_until` sont des dates sans heure, et l'ancien
     * fichier les rendait pourtant en date-heure — le premier instant du jour
     * pour un début, le dernier pour une fin (`30/09/2026 23:59:59`). L'heure
     * est donc posée ici, littéralement, plutôt que laissée à une conversion de
     * fuseau qui transformait minuit en `02:00:00` au passage.
     */
    private static function frDayBound(string $value, bool $endOfDay): string
    {
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return '';
        }

        $ts = strtotime($value);

        if ($ts === false) {
            return $value;
        }

        return wp_date('d/m/Y', $ts) . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }

    /**
     * Date et heure à la française, comme l'ancien extrait les écrivait.
     */
    private static function frDateTime(string $value): string
    {
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return '';
        }

        $ts = strtotime($value);

        if ($ts === false) {
            return $value;
        }

        return wp_date('d/m/Y H:i:s', $ts);
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
