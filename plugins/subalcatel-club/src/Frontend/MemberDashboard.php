<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Policy\EligibilityPolicy;

/**
 * Tableau de bord du membre : shortcode [subalcatel_espace_membre].
 *
 * Une seule question guide cet écran : qu'est-ce que je dois faire ? Ce qui
 * appelle une action passe en tête, avec le lien pour la traiter. Le reste —
 * ce qui va bien — vient après, en plus discret.
 *
 * C'est l'inverse d'un tableau de bord classique, qui commence par des
 * compteurs. Un compteur n'a jamais fait renouveler personne.
 */
final class MemberDashboard
{
    public static function register(): void
    {
        add_shortcode('subalcatel_espace_membre', [self::class, 'render']);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="sub-notice"><strong>Espace réservé aux membres</strong><p><a href="%s">Se connecter</a></p></div>',
                esc_url(wp_login_url(get_permalink()))
            );
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        $userId = get_current_user_id();
        $user   = wp_get_current_user();

        // Un compte non validé n'a rien à faire dans un tableau de bord : ni
        // adhésion, ni sortie, ni document à déposer. Lui montrer les rubriques
        // vides le laisserait chercher ce qui ne marche pas.
        if (\Subalcatel\Club\Identity\AccountApproval::isPending($userId)) {
            return self::pendingNotice($user->first_name ?: $user->display_name);
        }

        if (\Subalcatel\Club\Identity\AccountApproval::isRefused($userId)) {
            return '<div class="sub-notice sub-notice--error">'
                . '<p><strong>Votre compte n’a pas été validé par le bureau.</strong></p>'
                . '<p>Si vous pensez qu’il s’agit d’une erreur, contactez-le : la décision '
                . 'peut être réexaminée.</p></div>';
        }

        ob_start();
        ?>
        <div class="sub-dashboard">
            <p class="sub-dashboard__greeting">
                Bonjour <?php echo esc_html($user->first_name ?: $user->display_name); ?>.
                <?php
                $level = DiveLevels::forUser($userId);
                if ($level !== null) {
                    printf('Niveau %s.', esc_html($level->name));
                }
                ?>
            </p>

            <?php self::renderActions($userId); ?>
            <?php self::renderNextOutings($userId); ?>
            <?php self::renderOpenOutings($userId); ?>
            <?php self::renderOrganiser($userId); ?>
            <?php self::renderShortcuts(); ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Ce qui demande une action, et rien d'autre.
     */
    private static function renderActions(int $userId): void
    {
        $actions = self::collectActions($userId);

        if ($actions === []) {
            ?>
            <div class="sub-card-ok">
                <strong>Vous êtes à jour.</strong>
                <p>Adhésion, documents : tout est en règle. Bonnes bulles.</p>
            </div>
            <?php

            return;
        }
        ?>
        <section class="sub-todo">
            <h2 class="sub-todo__title">
                <?php echo count($actions) === 1 ? 'Une chose à faire' : 'À faire'; ?>
            </h2>

            <?php foreach ($actions as $action) : ?>
                <article class="sub-todo__item sub-todo__item--<?php echo esc_attr($action['level']); ?>">
                    <p class="sub-todo__what"><?php echo esc_html($action['title']); ?></p>
                    <p class="sub-todo__why"><?php echo esc_html($action['detail']); ?></p>
                    <?php if ($action['url'] !== '') : ?>
                        <a class="sub-button sub-button--small" href="<?php echo esc_url($action['url']); ?>">
                            <?php echo esc_html($action['action']); ?>
                        </a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
        <?php
    }

    /**
     * @return list<array{level: string, title: string, detail: string, action: string, url: string}>
     */
    private static function collectActions(int $userId): array
    {
        $policy    = new EligibilityPolicy();
        $documents = new DocumentService();
        $actions   = [];

        // 1. L'adhésion, parce qu'elle conditionne tout le reste.
        $membership = $policy->hasActiveMembership($userId);

        if (!$membership->allowed) {
            $actions[] = [
                'level'  => 'urgent',
                'title'  => 'Votre adhésion n’est pas à jour',
                'detail' => $membership->reason . ' Sans adhésion active, vous ne pouvez pas vous inscrire aux sorties.',
                'action' => 'Adhérer',
                'url'    => Pages::url(Pages::SUBSCRIBE),
            ];
        } else {
            $until = (string) get_user_meta($userId, 'sub_membership_valid_until', true);
            $days  = self::daysUntil($until);

            if ($days !== null && $days <= 45) {
                $actions[] = [
                    'level'  => 'soon',
                    'title'  => 'Votre adhésion arrive à échéance',
                    'detail' => sprintf('Elle se termine dans %d jours, le %s.', $days, self::frDate($until)),
                    'action' => 'Renouveler',
                    'url'    => Pages::url(Pages::SUBSCRIBE),
                ];
            }
        }

        // 2. Les documents, dans le détail : « un document manquant » ne dit pas
        //    lequel, et le membre repart sans savoir quoi faire.
        $status = $documents->statusFor($userId);

        foreach ($status['missing'] as $label) {
            $actions[] = [
                'level'  => 'urgent',
                'title'  => sprintf('%s à déposer', $label),
                'detail' => 'Ce document conditionne votre inscription aux plongées.',
                'action' => 'Déposer',
                'url'    => Pages::url(Pages::MY_DOCUMENTS),
            ];
        }

        foreach ($status['expired'] as $expired) {
            $actions[] = [
                'level'  => 'urgent',
                'title'  => sprintf('%s expiré', $expired['label']),
                'detail' => sprintf('Expiré depuis le %s.', self::frDate($expired['date'])),
                'action' => 'Déposer un nouveau document',
                'url'    => Pages::url(Pages::MY_DOCUMENTS),
            ];
        }

        foreach ($status['pending'] as $label) {
            $actions[] = [
                'level'  => 'waiting',
                'title'  => sprintf('%s en attente de validation', $label),
                'detail' => 'Le bureau le vérifiera prochainement. Rien à faire de votre côté.',
                'action' => '',
                'url'    => '',
            ];
        }

        // 3. Les documents à échéance proche.
        foreach ($documents->expiringSoon($userId, 45) as $soon) {
            $actions[] = [
                'level'  => 'soon',
                'title'  => sprintf('%s à renouveler', $soon['label']),
                'detail' => sprintf('Valable jusqu’au %s.', self::frDate($soon['date'])),
                'action' => 'Anticiper',
                'url'    => Pages::url(Pages::MY_DOCUMENTS),
            ];
        }

        return $actions;
    }

    private static function renderNextOutings(int $userId): void
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, r.status AS my_status
             FROM {$wpdb->prefix}sub_event_registrations r
             INNER JOIN {$wpdb->prefix}sub_events e ON e.id = r.event_id
             WHERE r.user_id = %d AND r.status IN ('confirmed','waiting')
               AND e.starts_at >= %s
             ORDER BY e.starts_at ASC LIMIT 5",
            $userId,
            current_time('mysql')
        ), ARRAY_A) ?: [];

        if ($rows === []) {
            return;
        }
        ?>
        <section class="sub-block">
            <h2 class="sub-block__title">Vos prochaines sorties</h2>

            <ul class="sub-list">
                <?php foreach ($rows as $row) : ?>
                    <li class="sub-list__item">
                        <span class="sub-list__main">
                            <strong><?php echo esc_html((string) $row['title']); ?></strong><br>
                            <?php echo esc_html(self::frDateTime((string) $row['starts_at'])); ?>
                            <?php if (!empty($row['location'])) : ?>
                                — <?php echo esc_html((string) $row['location']); ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($row['my_status'] === 'waiting') : ?>
                            <span class="sub-pill sub-pill--waiting">Liste d’attente</span>
                        <?php else : ?>
                            <span class="sub-pill sub-pill--ok">Inscrit</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    /**
     * Sorties auxquelles ce membre peut réellement s'inscrire.
     *
     * Proposer des sorties inaccessibles serait décourageant : on filtre par
     * l'éligibilité, pas seulement par la date.
     */
    private static function renderOpenOutings(int $userId): void
    {
        $service = new EventService();
        $open    = [];

        foreach ($service->upcoming(20) as $event) {
            $eventId = (int) $event['id'];

            if (self::isRegistered($eventId, $userId)) {
                continue;
            }

            if (!$service->checkEligibility($eventId, $userId)->allowed) {
                continue;
            }

            $open[] = $event;

            if (count($open) === 4) {
                break;
            }
        }

        if ($open === []) {
            return;
        }
        ?>
        <section class="sub-block">
            <h2 class="sub-block__title">Ouvert à votre niveau</h2>

            <ul class="sub-list">
                <?php foreach ($open as $event) : ?>
                    <?php
                    $confirmed = $service->confirmedCount((int) $event['id']);
                    $capacity  = (int) $event['capacity'];
                    ?>
                    <li class="sub-list__item">
                        <span class="sub-list__main">
                            <strong><?php echo esc_html((string) $event['title']); ?></strong><br>
                            <?php echo esc_html(self::frDateTime((string) $event['starts_at'])); ?>
                            <?php if ($capacity > 0) : ?>
                                — <?php echo esc_html(sprintf('%d/%d', $confirmed, $capacity)); ?>
                            <?php endif; ?>
                        </span>
                        <a class="sub-button sub-button--small" href="<?php echo esc_url(Pages::url(Pages::AGENDA)); ?>">
                            S’inscrire
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    /**
     * Proposition d'organiser une sortie, pour qui en a le droit.
     *
     * N'apparaît que si le membre peut réellement créer quelque chose : le droit
     * découle du niveau de plongée, pas d'une fonction au bureau. Montrer ce
     * bloc à tout le monde ferait de l'espace membre un catalogue de portes
     * fermées.
     *
     * Deux conditions distinctes, et non une seule : le droit de proposer une
     * sortie, et le fait d'en avoir déjà ouvert une. Un encadrant dont
     * l'adhésion vient d'expirer perd le premier — il garde le second, et avec
     * lui l'accès aux inscrits de la sortie de samedi.
     */
    private static function renderOrganiser(int $userId): void
    {
        $mayCreate = Pages::exists(Pages::NEW_OUTING)
            && (new EventService())->creatableTypesFor($userId) !== [];
        $hasOutings = Pages::exists(Pages::MY_OUTINGS)
            && OutingRoster::organisesAnything($userId);

        if (!$mayCreate && !$hasOutings) {
            return;
        }
        ?>
        <section class="sub-block">
            <h2 class="sub-block__title">Vous encadrez</h2>

            <?php if ($mayCreate) : ?>
                <p class="sub-help">
                    Votre niveau vous permet de proposer une sortie au club. Elle paraît
                    dans l’agenda dès sa publication.
                </p>
            <?php endif; ?>

            <p class="sub-organiser__links">
                <?php if ($mayCreate) : ?>
                    <a class="sub-button sub-button--small" href="<?php echo esc_url(Pages::url(Pages::NEW_OUTING)); ?>">
                        Organiser une sortie
                    </a>
                <?php endif; ?>
                <?php if ($hasOutings) : ?>
                    <a class="sub-button sub-button--small sub-button--ghost"
                       href="<?php echo esc_url(Pages::url(Pages::MY_OUTINGS)); ?>">
                        Mes sorties et leurs inscrits
                    </a>
                <?php endif; ?>
            </p>
        </section>
        <?php
    }

    /**
     * Écran d'attente, à la place du tableau de bord.
     *
     * Il dit trois choses et rien d'autre : c'est enregistré, ça se passe
     * ailleurs, et voilà quand vous saurez.
     */
    private static function pendingNotice(string $firstName): string
    {
        return sprintf(
            '<div class="sub-notice sub-notice--info">'
            . '<p><strong>Bonjour %s, votre compte est en attente de validation.</strong></p>'
            . '<p>Le bureau vérifie chaque nouvelle inscription. Vous recevrez un courriel '
            . 'dès que votre compte sera validé — vous pourrez alors constituer votre dossier '
            . 'd’adhésion et vous inscrire aux sorties.</p>'
            . '<p>En attendant, vous pouvez consulter %s et %s.</p></div>',
            esc_html($firstName),
            self::link(Pages::PRICING, 'les tarifs'),
            self::link(Pages::PUBLIC_AGENDA, 'l’agenda du club')
        );
    }

    /**
     * Lien vers une page, ou son libellé seul si la page n'existe pas.
     */
    private static function link(string $key, string $label): string
    {
        $url = Pages::url($key);

        return $url === ''
            ? esc_html($label)
            : sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($label));
    }

    private static function renderShortcuts(): void
    {
        $links = [
            Pages::AGENDA        => 'Agenda du club',
            Pages::MEMBERSHIP    => 'Mon adhésion',
            Pages::REGISTRATIONS => 'Mes inscriptions',
            Pages::MY_DOCUMENTS  => 'Mes documents',
            Pages::PROFILE       => 'Mon profil',
        ];
        ?>
        <nav class="sub-shortcuts" aria-label="Raccourcis">
            <?php foreach ($links as $slug => $label) : ?>
                <?php $url = Pages::url($slug); ?>
                <?php if ($url !== '') : ?>
                    <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    private static function isRegistered(int $eventId, int $userId): bool
    {
        global $wpdb;

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}sub_event_registrations
             WHERE event_id = %d AND user_id = %d",
            $eventId,
            $userId
        ));

        return $status !== null && $status !== 'cancelled';
    }

    private static function daysUntil(string $isoDate): ?int
    {
        if ($isoDate === '') {
            return null;
        }

        $target = strtotime($isoDate);

        if ($target === false) {
            return null;
        }

        return (int) floor(($target - strtotime(current_time('Y-m-d'))) / 86400);
    }

    public static function frDate(string $isoDate): string
    {
        $ts = strtotime($isoDate);

        return $ts === false ? $isoDate : wp_date('j F Y', $ts);
    }

    public static function frDateTime(string $mysqlDate): string
    {
        $ts = strtotime($mysqlDate);

        return $ts === false ? $mysqlDate : wp_date('l j F Y à H\hi', $ts);
    }
}
