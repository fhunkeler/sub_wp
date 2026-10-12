<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\MembershipCertificate;
use Subalcatel\Club\Membership\PaymentMethods;

/**
 * Adhésions : les dossiers, et les campagnes qui en fixent les tarifs.
 *
 * Le choix de wp-admin pour le bureau est délibéré : un trésorier qui traite
 * quatre-vingts dossiers a besoin de tableaux denses et filtrables, ce que
 * WordPress fait déjà bien. Les membres, eux, restent en front-office.
 *
 * Dossiers et campagnes partagent une entrée de menu parce qu'ils partagent une
 * question — combien, et jusqu'à quand — et surtout une personne : le trésorier
 * ouvre la campagne en septembre, les dossiers toute l'année.
 */
final class ApplicationsScreen
{
    public const SLUG = 'subalcatel-adhesions';

    /** Onglet des dossiers. */
    public const TAB = 'dossiers';

    /** @var list<string> */
    public const CAPABILITIES = ['sub_manage_memberships'];

    public static function register(): void
    {
        add_action('admin_post_sub_record_payment', [self::class, 'handlePayment']);
        add_action('admin_post_sub_validate_secretariat', [self::class, 'handleValidation']);
        add_action('admin_post_sub_cancel_membership', [self::class, 'handleCancel']);
    }

    public static function render(): void
    {
        AdminUi::tabbedScreen(self::SLUG, 'Adhésions', [
            self::TAB              => [
                'label'  => 'Dossiers',
                'cap'    => 'sub_manage_memberships',
                'count'  => self::pendingCount(),
                'render' => [self::class, 'renderApplications'],
            ],
            CampaignsScreen::TAB   => [
                'label'  => 'Campagnes',
                'cap'    => 'sub_manage_memberships',
                'render' => [CampaignsScreen::class, 'renderTab'],
            ],
        ]);
    }

    /**
     * Ce qui attend une décision derrière l'entrée « Adhésions ».
     *
     * Un dossier en attente de paiement regarde la trésorerie, un dossier en
     * attente de validation regarde le secrétariat : on ne compte que ce que
     * la personne devant l'écran peut elle-même faire avancer, sinon le
     * compteur annonce du travail que personne ici ne peut traiter.
     */
    public static function pendingCount(): int
    {
        $service = new ApplicationService();
        $count   = 0;

        if (current_user_can('sub_validate_membership_treasury')) {
            $count += $service->countByStatus(ApplicationService::STATUS_AWAITING_PAYMENT);
        }

        if (current_user_can('sub_validate_membership_secretariat')) {
            $count += $service->countByStatus(ApplicationService::STATUS_PAYMENT_CONFIRMED);
        }

        return $count;
    }

    /** Valeur du filtre d'état qui regroupe les deux étapes en attente. */
    public const PENDING = 'a_traiter';

    /** Valeur du filtre de campagne qui les montre toutes. */
    public const ALL_CAMPAIGNS = 'toutes';

    /**
     * Plafond d'affichage. Il ne coupe plus une saison en deux — une campagne
     * compte moins de cent dossiers —, seulement la vue « toutes campagnes »,
     * et l'écran le dit quand il s'applique.
     */
    private const LIMIT = 200;

    /** @var array<string, string> */
    private const STATES = [
        ApplicationService::STATUS_AWAITING_PAYMENT  => 'En attente de paiement',
        ApplicationService::STATUS_PAYMENT_CONFIRMED => 'À valider (secrétariat)',
        ApplicationService::STATUS_ACTIVE            => 'Actives',
        ApplicationService::STATUS_REFUSED           => 'Refusées',
        ApplicationService::STATUS_CANCELLED         => 'Annulées',
    ];

    /**
     * Les filtres demandés, lus depuis l'URL.
     *
     * Sans campagne choisie, la liste s'ouvre sur la campagne en cours : c'est
     * elle que le bureau traite, et les saisons closes — quatre-vingts dossiers
     * actifs chacune — noyaient les trois qui attendent quelqu'un.
     *
     * @return array{campaign: int, state: string, search: string}
     */
    public static function currentFilters(): array
    {
        $raw = sanitize_key((string) ($_GET['campagne'] ?? ''));

        if ($raw === self::ALL_CAMPAIGNS) {
            $campaign = 0;
        } elseif ($raw !== '' && ctype_digit($raw)) {
            $campaign = (int) $raw;
        } else {
            $current  = (new CampaignRepository())->campaignToShow();
            $campaign = $current !== null ? (int) $current['campaign']['id'] : 0;
        }

        $state = sanitize_key((string) ($_GET['etat'] ?? ''));

        if ($state !== self::PENDING && !isset(self::STATES[$state])) {
            $state = '';
        }

        return [
            'campaign' => $campaign,
            'state'    => $state,
            'search'   => sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))),
        ];
    }

    /**
     * Les campagnes proposées au filtre, la plus récente d'abord, avec ce
     * qu'elles contiennent et ce qui y attend une décision.
     *
     * Un brouillon sans dossier n'y figure pas : il n'a rien à montrer.
     *
     * @return list<array{id: int, title: string, total: int, pending: int}>
     */
    public static function campaigns(): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $rows = $wpdb->get_results(
            "SELECT c.id, c.title, COUNT(a.id) AS total,
                    COALESCE(SUM(a.status IN ('awaiting_payment','payment_confirmed')), 0) AS pending
             FROM {$p}campaigns c
             LEFT JOIN {$p}applications a ON a.campaign_id = c.id
             GROUP BY c.id, c.title, c.status, c.valid_from, c.opens_on
             HAVING total > 0 OR c.status <> 'draft'
             ORDER BY c.valid_from DESC, c.opens_on DESC",
            ARRAY_A
        ) ?: [];

        return array_map(static fn (array $row): array => [
            'id'      => (int) $row['id'],
            'title'   => (string) $row['title'],
            'total'   => (int) $row['total'],
            'pending' => (int) $row['pending'],
        ], $rows);
    }

    /**
     * Les dossiers qui répondent aux filtres.
     *
     * @param array{campaign: int, state: string, search: string} $filters
     * @return list<array<string, mixed>>
     */
    public static function applications(array $filters): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $where  = ['1=1'];
        $params = [];

        if ($filters['campaign'] > 0) {
            $where[]  = 'a.campaign_id = %d';
            $params[] = $filters['campaign'];
        }

        if ($filters['state'] === self::PENDING) {
            $where[]  = "a.status IN ('awaiting_payment','payment_confirmed')";
        } elseif ($filters['state'] !== '') {
            $where[]  = 'a.status = %s';
            $params[] = $filters['state'];
        }

        if ($filters['search'] !== '') {
            $like     = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s OR a.reference LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        // Le montant encaissé est joint plutôt que compté ligne à ligne : une
        // correction peut creuser un écart avec le montant dû, et c'est un écart
        // qui doit se voir sur la liste, pas au dossier ouvert.
        $sql = "SELECT a.*, u.display_name, u.user_email, pl.title AS plan_title,
                       c.title AS campaign_title, COALESCE(pay.paid, 0) AS paid_amount
                FROM {$p}applications a
                LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
                LEFT JOIN {$p}plans pl ON pl.id = a.plan_id
                LEFT JOIN {$p}campaigns c ON c.id = a.campaign_id
                LEFT JOIN (
                    SELECT application_id, SUM(amount) AS paid
                    FROM {$p}payments WHERE status = 'received'
                    GROUP BY application_id
                ) pay ON pay.application_id = a.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY FIELD(a.status,'awaiting_payment','payment_confirmed','active','refused'),
                         a.created_at DESC
                LIMIT " . self::LIMIT;

        return $wpdb->get_results(
            $params === [] ? $sql : $wpdb->prepare($sql, ...$params),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Nombre de dossiers par état, pour une campagne (0 = toutes).
     *
     * Compté en base, et non sur les lignes affichées : les tuiles ne doivent
     * pas changer de valeur selon le filtre d'état qu'elles servent à poser.
     *
     * @return array<string, int>
     */
    public static function stateCounts(int $campaignId): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'sub_';

        $sql = "SELECT status, COUNT(*) AS n FROM {$p}applications"
            . ($campaignId > 0 ? ' WHERE campaign_id = %d' : '')
            . ' GROUP BY status';

        $rows = $wpdb->get_results(
            $campaignId > 0 ? $wpdb->prepare($sql, $campaignId) : $sql,
            ARRAY_A
        ) ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Adresse de la liste pour d'autres filtres que ceux en cours.
     *
     * @param array{campaign: int, state: string, search: string} $filters
     */
    private static function listUrl(array $filters): string
    {
        return add_query_arg(array_filter([
            'campagne' => $filters['campaign'] > 0 ? (string) $filters['campaign'] : self::ALL_CAMPAIGNS,
            'etat'     => $filters['state'],
            's'        => $filters['search'] === '' ? '' : rawurlencode($filters['search']),
        ]), AdminUi::tabUrl(self::SLUG, self::TAB));
    }

    public static function renderApplications(): void
    {
        AdminUi::requireCap('sub_manage_memberships');

        $filters   = self::currentFilters();
        $campaigns = self::campaigns();
        $rows      = self::applications($filters);
        $counts    = self::stateCounts($filters['campaign']);
        $allView   = $filters['campaign'] === 0;

        // Ce qui attend ailleurs que dans la campagne affichée. Pendant le
        // recouvrement de deux saisons, un renouvellement en retard reste
        // rattaché à l'ancienne campagne : il ne doit pas disparaître de la
        // vue du trésorier parce que la nouvelle s'ouvre par défaut.
        $pendingElsewhere = 0;
        if (!$allView) {
            foreach ($campaigns as $campaign) {
                if ($campaign['id'] !== $filters['campaign']) {
                    $pendingElsewhere += $campaign['pending'];
                }
            }
        }

        $service = new ApplicationService();
        ?>
            <form method="get" class="sub-filters"
                  style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 16px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <input type="hidden" name="tab" value="<?php echo esc_attr(self::TAB); ?>">

                <label class="screen-reader-text" for="sub-filter-campaign">Campagne</label>
                <select name="campagne" id="sub-filter-campaign">
                    <?php foreach ($campaigns as $campaign) : ?>
                        <option value="<?php echo esc_attr((string) $campaign['id']); ?>"
                            <?php selected($filters['campaign'], $campaign['id']); ?>>
                            <?php
                            echo esc_html(sprintf(
                                '%s — %d dossier%s%s',
                                $campaign['title'],
                                $campaign['total'],
                                $campaign['total'] > 1 ? 's' : '',
                                $campaign['pending'] > 0 ? sprintf(', %d à traiter', $campaign['pending']) : ''
                            ));
                            ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="<?php echo esc_attr(self::ALL_CAMPAIGNS); ?>" <?php selected($allView); ?>>
                        Toutes les campagnes
                    </option>
                </select>

                <label class="screen-reader-text" for="sub-filter-state">État</label>
                <select name="etat" id="sub-filter-state">
                    <option value="">Tous les états</option>
                    <option value="<?php echo esc_attr(self::PENDING); ?>" <?php selected($filters['state'], self::PENDING); ?>>
                        À traiter
                    </option>
                    <?php foreach (self::STATES as $status => $label) : ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected($filters['state'], $status); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="screen-reader-text" for="sub-filter-search">Rechercher</label>
                <input type="search" name="s" id="sub-filter-search"
                       value="<?php echo esc_attr($filters['search']); ?>"
                       placeholder="Nom, courriel ou référence">

                <button class="button">Filtrer</button>
                <?php if ($filters['state'] !== '' || $filters['search'] !== '' || isset($_GET['campagne'])) : ?>
                    <a class="button-link" href="<?php echo esc_url(AdminUi::tabUrl(self::SLUG, self::TAB)); ?>">
                        Réinitialiser
                    </a>
                <?php endif; ?>
            </form>

            <?php if ($pendingElsewhere > 0) : ?>
                <div class="notice notice-warning inline" style="margin:0 0 16px;">
                    <p>
                        <?php
                        echo esc_html(sprintf(
                            '%d dossier%s à traiter sur une autre campagne.',
                            $pendingElsewhere,
                            $pendingElsewhere > 1 ? 's' : ''
                        ));
                        ?>
                        <a href="<?php echo esc_url(self::listUrl(['campaign' => 0, 'state' => self::PENDING, 'search' => ''])); ?>">
                            Les afficher
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <?php // Les tuiles posent le filtre d'état : un clic sur « En attente
                  // de paiement » donne la liste des chèques à encaisser. ?>
            <ul class="subwrap-stats" style="display:flex;flex-wrap:wrap;gap:16px;list-style:none;margin:0 0 24px;padding:0;">
                <?php foreach ([
                    ApplicationService::STATUS_AWAITING_PAYMENT  => 'En attente de paiement',
                    ApplicationService::STATUS_PAYMENT_CONFIRMED => 'À valider (secrétariat)',
                    ApplicationService::STATUS_ACTIVE            => 'Actives',
                ] as $status => $label) : ?>
                    <?php $isCurrent = $filters['state'] === $status; ?>
                    <li>
                        <a href="<?php echo esc_url(self::listUrl(array_merge($filters, ['state' => $isCurrent ? '' : $status]))); ?>"
                           <?php echo $isCurrent ? 'aria-current="true"' : ''; ?>
                           style="display:block;background:#fff;border:<?php echo $isCurrent ? '2px solid #1d5480' : '1px solid #c3c4c7'; ?>;border-radius:8px;padding:12px 18px;color:inherit;text-decoration:none;">
                            <strong style="font-size:24px;display:block;"><?php echo (int) ($counts[$status] ?? 0); ?></strong>
                            <span style="color:#50575e;"><?php echo esc_html($label); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if (count($rows) >= self::LIMIT) : ?>
                <p class="description">
                    Seuls les <?php echo (int) self::LIMIT; ?> premiers dossiers sont affichés :
                    choisissez une campagne ou un état pour voir les autres.
                </p>
            <?php endif; ?>

            <div class="sub-scroll"><table class="wp-list-table widefat striped sub-cards sub-table--wide">
                <thead>
                    <tr>
                        <th style="width:140px;">Référence</th>
                        <th>Membre</th>
                        <th style="width:130px;">Formule</th>
                        <th style="width:100px;">Montant</th>
                        <th style="width:130px;">Règlement annoncé</th>
                        <th style="width:170px;">État</th>
                        <th style="width:470px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td colspan="7">
                        <?php echo $filters['state'] !== '' || $filters['search'] !== ''
                            ? 'Aucun dossier ne répond à ces critères.'
                            : 'Aucun dossier pour l’instant.'; ?>
                    </td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td data-label="Référence"><code><?php echo esc_html((string) $row['reference']); ?></code></td>
                        <td data-label="Membre">
                            <?php if ($row['user_id'] === null) : ?>
                                <?php // Le dossier reste — c'est une pièce comptable — mais son
                                      // titulaire a supprimé son compte. Le dire, plutôt que
                                      // laisser une ligne vide qu'on croira cassée. ?>
                                <em style="color:#50575e;">Compte supprimé</em>
                            <?php else : ?>
                                <strong><?php echo esc_html((string) ($row['display_name'] ?: '—')); ?></strong><br>
                                <span style="color:#50575e;"><?php echo esc_html((string) $row['user_email']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Formule">
                            <?php echo esc_html((string) $row['plan_title']); ?>
                            <?php if ($allView) : ?>
                                <br><small style="color:#50575e;"><?php echo esc_html((string) $row['campaign_title']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Montant" style="font-variant-numeric:tabular-nums;">
                            <?php echo esc_html(AdminUi::euro((float) $row['total_amount'])); ?>
                            <?php self::renderPaymentGap($row); ?>
                        </td>
                        <td data-label="Règlement annoncé">
                            <?php
                            $announced = (string) ($row['payment_method'] ?? '');
                            echo esc_html($announced === '' ? '—' : PaymentMethods::label($announced));
                            ?>
                        </td>
                        <td data-label="État"><?php echo AdminUi::statusBadge((string) $row['status']); ?></td>
                        <td data-label="Action"><?php self::renderActions($row); ?></td>
                    </tr>
                    <tr class="sub-cards__detail">
                        <td colspan="7" data-label="Détail" style="background:#fbfbfb;">
                            <?php self::renderLines($service, (int) $row['id']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderActions(array $row): void
    {
        echo '<div class="sub-row-actions">';

        self::renderStepAction($row);

        // Corriger reste possible tant que le dossier n'est pas activé, quelle
        // que soit l'étape : l'erreur de saisie se découvre aussi bien à
        // l'encaissement du chèque qu'à la vérification des pièces.
        if (
            ApplicationService::isEditable((string) $row['status'])
            && current_user_can('sub_manage_memberships')
        ) {
            printf(
                '<a class="button" href="%s">Corriger</a>',
                esc_url(ApplicationEditor::url((int) $row['id']))
            );

            self::renderCancel((int) $row['id']);
        }

        // Le bureau délivre les mêmes pièces que l'adhérent : c'est à lui
        // qu'on les réclame encore, par courriel ou à la permanence.
        if (current_user_can('sub_manage_memberships')) {
            foreach (MembershipCertificate::available($row) as $kind) {
                printf(
                    '<a class="button" href="%s">%s</a>',
                    esc_url(MembershipCertificate::url((int) $row['id'], $kind)),
                    esc_html($kind === MembershipCertificate::ATTESTATION ? 'Attestation' : 'Reçu')
                );
            }
        }

        echo '</div>';
    }

    /**
     * Annuler un dossier non activé.
     *
     * L'écran de correction promettait déjà cette sortie — « pour corriger un
     * dossier validé, il faut l'annuler et en déposer une nouvelle » — sans que
     * rien ne la propose : le statut existait en base, aucun geste ne le posait.
     * Le bureau y arrive par deux chemins : le doublon déposé par un adhérent
     * inquiet, et le dossier de test resté dans la liste.
     */
    private static function renderCancel(int $applicationId): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              class="sub-step-form"
              onsubmit="return confirm('Annuler ce dossier ? Il restera visible, marqué « annulée ».');">
            <input type="hidden" name="action" value="sub_cancel_membership">
            <input type="hidden" name="application_id" value="<?php echo esc_attr((string) $applicationId); ?>">
            <?php wp_nonce_field('sub_cancel_membership_' . $applicationId); ?>
            <input type="text" name="reason" placeholder="Motif" style="width:120px;">
            <button class="button">Annuler</button>
        </form>
        <?php
    }

    /**
     * Annulation prononcée par le bureau.
     */
    public static function handleCancel(): void
    {
        $applicationId = isset($_POST['application_id']) ? absint($_POST['application_id']) : 0;

        check_admin_referer('sub_cancel_membership_' . $applicationId);
        AdminUi::requireCap('sub_manage_memberships');

        $reason = isset($_POST['reason'])
            ? sanitize_text_field(wp_unslash($_POST['reason']))
            : '';

        try {
            (new ApplicationService())->cancel($applicationId, get_current_user_id(), $reason);
        } catch (\RuntimeException $e) {
            wp_die(esc_html($e->getMessage()), 409);
        }

        wp_safe_redirect(add_query_arg(self::refererFilters(), AdminUi::tabUrl(self::SLUG, self::TAB)));
        exit;
    }

    /**
     * L'écart entre ce qui est dû et ce qui a été encaissé.
     *
     * Il n'apparaît que lorsqu'il existe. Un dossier corrigé après règlement est
     * le seul cas courant — et le seul où le trésorier doit relancer quelqu'un.
     *
     * @param array<string, mixed> $row
     */
    private static function renderPaymentGap(array $row): void
    {
        $paid = (float) ($row['paid_amount'] ?? 0);
        $gap  = round((float) $row['total_amount'] - $paid, 2);

        if ($paid <= 0 || abs($gap) < 0.005) {
            return;
        }

        printf(
            '<br><small style="color:%s;">%s réglés — %s %s</small>',
            $gap > 0 ? '#b82a1e' : '#c0561a',
            esc_html(AdminUi::euro($paid)),
            esc_html(AdminUi::euro(abs($gap))),
            $gap > 0 ? 'à encaisser' : 'à rembourser'
        );
    }

    /**
     * L'action qui fait avancer le dossier d'une étape.
     *
     * @param array<string, mixed> $row
     */
    private static function renderStepAction(array $row): void
    {
        $id = (int) $row['id'];

        if ($row['status'] === ApplicationService::STATUS_AWAITING_PAYMENT) {
            if (!current_user_can('sub_validate_membership_treasury')) {
                echo '<em>Réservé à la trésorerie.</em>';

                return;
            }
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  class="sub-step-form">
                <input type="hidden" name="action" value="sub_record_payment">
                <input type="hidden" name="application_id" value="<?php echo esc_attr((string) $id); ?>">
                <?php wp_nonce_field('sub_record_payment_' . $id); ?>
                <?php // Présélectionné sur ce que l'adhérent a annoncé : la trésorerie
                      // n'a plus qu'à confirmer, et l'écart se voit s'il y en a un. ?>
                <select name="method">
                    <?php foreach (PaymentMethods::offered() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"
                                <?php selected($value, (string) ($row['payment_method'] ?? '')); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="reference" placeholder="Référence" style="width:110px;">
                <button class="button button-primary">Paiement reçu</button>
            </form>
            <?php

            return;
        }

        if ($row['status'] === ApplicationService::STATUS_PAYMENT_CONFIRMED) {
            if (!current_user_can('sub_validate_membership_secretariat')) {
                echo '<em>Réservé au secrétariat.</em>';

                return;
            }
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  class="sub-step-form">
                <input type="hidden" name="action" value="sub_validate_secretariat">
                <input type="hidden" name="application_id" value="<?php echo esc_attr((string) $id); ?>">
                <?php wp_nonce_field('sub_validate_secretariat_' . $id); ?>
                <input type="text" name="comment" placeholder="Commentaire" style="width:150px;">
                <button class="button button-primary">Valider le dossier</button>
            </form>
            <?php

            return;
        }

        echo '—';
    }

    private static function renderLines(ApplicationService $service, int $applicationId): void
    {
        $lines = $service->lines($applicationId);

        if ($lines === []) {
            return;
        }

        echo '<div style="display:flex;flex-wrap:wrap;gap:6px 18px;font-size:12px;color:#50575e;">';

        foreach ($lines as $line) {
            $label = $line['value_label'] !== null && $line['value_label'] !== ''
                ? $line['label'] . ' — ' . $line['value_label']
                : $line['label'];

            $color = $line['line_type'] === 'discount' ? '#17795e' : 'inherit';

            printf(
                '<span style="color:%s;">%s <strong>%s €</strong></span>',
                esc_attr($color),
                esc_html((string) $label),
                esc_html(number_format((float) $line['amount'], 2, ',', ' '))
            );
        }

        echo '</div>';
    }


    public static function handlePayment(): void
    {
        $id = isset($_POST['application_id']) ? absint($_POST['application_id']) : 0;
        check_admin_referer('sub_record_payment_' . $id);

        $service     = new ApplicationService();
        $application = $service->find($id);

        try {
            if ($application === null) {
                throw new \RuntimeException('Dossier introuvable.');
            }

            $service->recordPayment(
                $id,
                (float) $application['total_amount'],
                sanitize_key(wp_unslash((string) ($_POST['method'] ?? 'cheque'))),
                null,
                get_current_user_id(),
                sanitize_text_field(wp_unslash((string) ($_POST['reference'] ?? '')))
            );

            self::redirect('Paiement enregistré.');
        } catch (\RuntimeException $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    public static function handleValidation(): void
    {
        $id = isset($_POST['application_id']) ? absint($_POST['application_id']) : 0;
        check_admin_referer('sub_validate_secretariat_' . $id);

        try {
            (new ApplicationService())->validateSecretariat(
                $id,
                get_current_user_id(),
                sanitize_text_field(wp_unslash((string) ($_POST['comment'] ?? '')))
            );

            self::redirect('Dossier validé : l’adhésion est active.');
        } catch (\RuntimeException $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    private static function redirect(string $message, bool $isError = false): never
    {
        AdminUi::redirect(self::SLUG, $message, $isError, ['tab' => self::TAB] + self::refererFilters());
    }

    /**
     * Les filtres de la liste d'où part le geste.
     *
     * Le trésorier qui encaisse les chèques en attente d'une campagne enchaîne
     * dix dossiers : revenir après chacun sur la liste par défaut lui ferait
     * reposer ses filtres dix fois.
     *
     * @return array<string, string>
     */
    private static function refererFilters(): array
    {
        $referer = wp_get_referer();

        if ($referer === false) {
            return [];
        }

        $args = [];
        wp_parse_str((string) wp_parse_url($referer, PHP_URL_QUERY), $args);

        $kept = [];
        foreach (['campagne', 'etat'] as $key) {
            if (isset($args[$key]) && is_string($args[$key]) && $args[$key] !== '') {
                $kept[$key] = sanitize_key($args[$key]);
            }
        }

        if (isset($args['s']) && is_string($args['s']) && $args['s'] !== '') {
            $kept['s'] = rawurlencode(sanitize_text_field($args['s']));
        }

        return $kept;
    }
}
