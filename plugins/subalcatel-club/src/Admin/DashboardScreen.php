<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Identity\LegalGuardian;
use Subalcatel\Club\Membership\ApplicationService;

/**
 * Tableau de bord du bureau.
 *
 * Un tableau de bord n'est pas un mur de compteurs : ceux-ci ne disent pas
 * quoi faire. Chaque bloc ci-dessous répond à « qu'est-ce qui attend
 * quelqu'un ? » et mène à l'écran où l'on agit. Les statistiques viennent
 * après, en bas, parce qu'elles se consultent une fois par trimestre.
 *
 * **Filtré par capacité, pas seulement à l'affichage** : un membre du bureau
 * chargé des événements ne voit pas les dossiers financiers en attente. Un
 * bloc vide de sens pour la personne est un bloc absent.
 */
final class DashboardScreen
{
    /** Fenêtre des adhésions arrivant à échéance, en jours. */
    private const EXPIRY_WINDOW = 30;

    /**
     * Longueur maximale d'une liste du tableau de bord.
     *
     * Une liste plus longue ne se lit plus, et son compteur cesse d'être exact :
     * c'est pourquoi la constante est publique — le bloc du tableau de bord de
     * WordPress a besoin de savoir à partir de quel nombre il doit écrire « + ».
     */
    public const LIST_LIMIT = 10;

    public static function render(): void
    {
        wp_enqueue_style(
            'subalcatel-admin',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/admin.css',
            [],
            \Subalcatel\Club\VERSION
        );

        $user = wp_get_current_user();

        echo '<div class="wrap"><h1>Vue d’ensemble</h1>';

        printf(
            '<p class="description">Bonjour %s. Voici ce qui attend le bureau aujourd’hui.</p>',
            esc_html($user->first_name !== '' ? $user->first_name : $user->display_name)
        );

        echo '<div class="sub-dashboard">';

        $blocks = self::blocks();

        if ($blocks === []) {
            echo '<p>Rien ne requiert votre attention.</p>';
        }

        foreach ($blocks as $block) {
            self::renderBlock($block);
        }

        echo '</div>';

        // Ordre voulu : ce qui attend quelqu'un, puis ce qui vient, puis ce qui
        // se compte. Les courbes tiennent le milieu — un échéancier de
        // certificats ou une sortie creuse se regardent au mois, pas au jour.
        DashboardCharts::render();

        self::renderStats();

        echo '</div>';
    }

    /**
     * Les listes actionnables, dans l'ordre de l'urgence.
     *
     * @return list<array{title: string, empty: string, items: list<array{label: string, meta: string, url: string}>, url: string, cta: string, tone: string}>
     */
    public static function blocks(): array
    {
        $blocks = [];

        foreach ([
            self::pendingApplications(...),
            self::pendingPayments(...),
            self::pendingDocuments(...),
            self::expiringMemberships(...),
            self::upcomingEvents(...),
            self::incompleteMinors(...),
        ] as $builder) {
            $block = $builder();

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function pendingApplications(): ?array
    {
        if (!current_user_can('sub_validate_membership_secretariat')) {
            return null;
        }

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.reference, a.created_at, u.display_name
             FROM {$wpdb->prefix}sub_applications a
             LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
             WHERE a.status = %s ORDER BY a.created_at ASC LIMIT " . self::LIST_LIMIT,
            ApplicationService::STATUS_PAYMENT_CONFIRMED
        ), ARRAY_A) ?: [];

        return self::block(
            'Dossiers à valider',
            'Aucun dossier n’attend votre validation.',
            array_map(static fn (array $r): array => [
                'label' => (string) $r['display_name'],
                'meta'  => sprintf('%s — reçu le %s', $r['reference'], mysql2date('j M', (string) $r['created_at'])),
                'url'   => AdminUi::tabUrl(ApplicationsScreen::SLUG, ApplicationsScreen::TAB),
            ], $rows),
            AdminUi::tabUrl(ApplicationsScreen::SLUG, ApplicationsScreen::TAB),
            'Voir les dossiers',
            'action'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function pendingPayments(): ?array
    {
        if (!current_user_can('sub_validate_membership_treasury')) {
            return null;
        }

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.reference, a.total_amount, a.created_at, u.display_name
             FROM {$wpdb->prefix}sub_applications a
             LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
             WHERE a.status IN (%s, %s) ORDER BY a.created_at ASC LIMIT " . self::LIST_LIMIT,
            ApplicationService::STATUS_SUBMITTED,
            ApplicationService::STATUS_AWAITING_PAYMENT
        ), ARRAY_A) ?: [];

        return self::block(
            'Règlements attendus',
            'Tous les règlements sont encaissés.',
            array_map(static fn (array $r): array => [
                'label' => (string) $r['display_name'],
                'meta'  => sprintf(
                    '%s — %s €',
                    $r['reference'],
                    number_format((float) $r['total_amount'], 2, ',', ' ')
                ),
                'url'   => AdminUi::tabUrl(ApplicationsScreen::SLUG, ApplicationsScreen::TAB),
            ], $rows),
            AdminUi::tabUrl(ApplicationsScreen::SLUG, ApplicationsScreen::TAB),
            'Saisir un règlement',
            'action'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function pendingDocuments(): ?array
    {
        if (!current_user_can('sub_validate_member_document')) {
            return null;
        }

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.uploaded_at, t.label, u.display_name
             FROM {$wpdb->prefix}sub_member_documents d
             LEFT JOIN {$wpdb->prefix}sub_document_types t ON t.slug = d.type_slug
             LEFT JOIN {$wpdb->users} u ON u.ID = d.user_id
             WHERE d.status = %s ORDER BY d.uploaded_at ASC LIMIT " . self::LIST_LIMIT,
            DocumentService::STATUS_PENDING
        ), ARRAY_A) ?: [];

        return self::block(
            'Documents à vérifier',
            'Aucun document en attente.',
            array_map(static fn (array $r): array => [
                'label' => (string) $r['display_name'],
                'meta'  => sprintf('%s — déposé le %s', $r['label'], mysql2date('j M', (string) $r['uploaded_at'])),
                'url'   => AdminUi::tabUrl(MembersScreen::SLUG, DocumentsScreen::TAB),
            ], $rows),
            AdminUi::tabUrl(MembersScreen::SLUG, DocumentsScreen::TAB),
            'Vérifier',
            'action'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function expiringMemberships(): ?array
    {
        if (!current_user_can('sub_manage_memberships')) {
            return null;
        }

        global $wpdb;

        $today    = current_time('Y-m-d');
        $deadline = gmdate('Y-m-d', strtotime($today . ' +' . self::EXPIRY_WINDOW . ' days'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.reference, a.valid_until, u.display_name
             FROM {$wpdb->prefix}sub_applications a
             LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
             WHERE a.status = %s AND a.valid_until BETWEEN %s AND %s
             ORDER BY a.valid_until ASC LIMIT " . self::LIST_LIMIT,
            ApplicationService::STATUS_ACTIVE,
            $today,
            $deadline
        ), ARRAY_A) ?: [];

        return self::block(
            sprintf('Adhésions expirant sous %d jours', self::EXPIRY_WINDOW),
            'Aucune adhésion n’arrive à échéance.',
            array_map(static fn (array $r): array => [
                'label' => (string) $r['display_name'],
                'meta'  => 'jusqu’au ' . mysql2date('j M Y', (string) $r['valid_until']),
                'url'   => admin_url('admin.php?page=' . MembersScreen::SLUG),
            ], $rows),
            admin_url('admin.php?page=' . MembersScreen::SLUG),
            'Voir les membres',
            'watch'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function upcomingEvents(): ?array
    {
        $events = (new EventService())->upcoming(self::LIST_LIMIT);

        return self::block(
            'Prochaines sorties',
            'Aucune sortie programmée.',
            array_map(static fn (array $e): array => [
                'label' => (string) $e['title'],
                'meta'  => mysql2date('j M à H\hi', (string) $e['starts_at'])
                    . ((string) ($e['location'] ?? '') !== '' ? ' — ' . $e['location'] : ''),
                'url'   => admin_url('admin.php?page=' . EventsScreen::SLUG),
            ], $events),
            admin_url('admin.php?page=' . EventsScreen::SLUG),
            'Gérer les sorties',
            'info'
        );
    }

    /**
     * Mineurs sans représentant légal renseigné.
     *
     * À régulariser avant la première sortie, pas au bord de l'eau.
     *
     * @return array<string, mixed>|null
     */
    private static function incompleteMinors(): ?array
    {
        if (!current_user_can('sub_manage_memberships')) {
            return null;
        }

        global $wpdb;

        $threshold = gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' -' . LegalGuardian::MAJORITY_AGE . ' years'));

        $candidates = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'sub_birth_date' AND meta_value <> '' AND meta_value > %s",
            $threshold
        ));

        $items = [];

        foreach (array_map('intval', $candidates) as $userId) {
            if (!LegalGuardian::isIncomplete($userId)) {
                continue;
            }

            $user = get_userdata($userId);

            $items[] = [
                'label' => $user ? $user->display_name : '#' . $userId,
                'meta'  => sprintf('%d ans — aucun représentant légal', (int) LegalGuardian::ageOf($userId)),
                'url'   => admin_url('admin.php?page=' . MembersScreen::SLUG),
            ];

            // Même plafond que les autres listes : au-delà, on ne lit plus, et
            // le compteur du bloc de WordPress annonce « 10 + » comme ailleurs.
            if (count($items) >= self::LIST_LIMIT) {
                break;
            }
        }

        if ($items === []) {
            return null;
        }

        return self::block(
            'Mineurs sans représentant légal',
            '',
            $items,
            admin_url('admin.php?page=' . MembersScreen::SLUG),
            'Compléter les fiches',
            'alert'
        );
    }

    /**
     * @param list<array{label: string, meta: string, url: string}> $items
     * @return array<string, mixed>
     */
    private static function block(
        string $title,
        string $empty,
        array $items,
        string $url,
        string $cta,
        string $tone,
    ): array {
        return compact('title', 'empty', 'items', 'url', 'cta', 'tone');
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderBlock(array $block): void
    {
        ?>
        <section class="sub-dash-card sub-dash-card--<?php echo esc_attr((string) $block['tone']); ?>">
            <h2>
                <?php echo esc_html((string) $block['title']); ?>
                <?php if ($block['items'] !== []) : ?>
                    <span class="sub-dash-count"><?php echo count($block['items']); ?></span>
                <?php endif; ?>
            </h2>

            <?php if ($block['items'] === []) : ?>
                <p class="sub-dash-empty"><?php echo esc_html((string) $block['empty']); ?></p>
            <?php else : ?>
                <ul class="sub-dash-list">
                    <?php foreach ($block['items'] as $item) : ?>
                        <li>
                            <a href="<?php echo esc_url((string) $item['url']); ?>">
                                <?php echo esc_html((string) $item['label']); ?>
                            </a>
                            <span class="sub-dash-meta"><?php echo esc_html((string) $item['meta']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="sub-dash-cta">
                    <a href="<?php echo esc_url((string) $block['url']); ?>">
                        <?php echo esc_html((string) $block['cta']); ?> →
                    </a>
                </p>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Statistiques de fond — B-09.
     *
     * @return array<string, int>
     */
    public static function stats(): array
    {
        global $wpdb;

        $today = current_time('Y-m-d');

        $activeMembers = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
             WHERE meta_key = 'sub_membership_valid_until' AND meta_value >= %s",
            $today
        ));

        $validCertificates = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}sub_member_documents
             WHERE type_slug = %s AND status = %s",
            DocumentTypes::MEDICAL,
            DocumentService::STATUS_VALID
        ));

        $upcomingEvents = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_events
             WHERE status = 'published' AND starts_at >= %s",
            current_time('mysql')
        ));

        $registrations = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_event_registrations WHERE status = 'confirmed'"
        );

        return [
            'Adhérents à jour'      => $activeMembers,
            'Certificats valides'   => $validCertificates,
            // L'écart entre les deux est l'information : ce sont les membres
            // qui ne peuvent pas plonger.
            'Certificats manquants' => max(0, $activeMembers - $validCertificates),
            'Sorties à venir'       => $upcomingEvents,
            'Inscriptions en cours' => $registrations,
        ];
    }

    private static function renderStats(): void
    {
        if (!current_user_can('sub_manage_memberships')) {
            return;
        }
        ?>
        <h2>En chiffres</h2>
        <div class="sub-dash-stats">
            <?php foreach (self::stats() as $label => $value) : ?>
                <div class="sub-dash-stat">
                    <span class="sub-dash-stat__value"><?php echo (int) $value; ?></span>
                    <span class="sub-dash-stat__label"><?php echo esc_html($label); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
