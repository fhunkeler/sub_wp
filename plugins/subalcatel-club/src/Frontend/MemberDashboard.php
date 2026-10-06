<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Admin\ClubMenu;
use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Identity\DerivedCapabilities;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Policy\EligibilityPolicy;

/**
 * Tableau de bord du membre : shortcode [subalcatel_espace_membre].
 *
 * Le membre arrive ici d'abord pour plonger : les sorties prévues ouvrent donc
 * l'écran, avec de quoi s'y inscrire sur place. Vient ensuite la question
 * qu'est-ce que je dois faire ? — ce qui appelle une action, avec le lien pour
 * la traiter. Le reste — ce qui va bien — vient après, en plus discret.
 *
 * C'est l'inverse d'un tableau de bord classique, qui commence par des
 * compteurs. Un compteur n'a jamais fait renouveler personne.
 */
final class MemberDashboard
{
    /** Au-delà, l'agenda prend le relais : l'espace n'a pas à le recopier. */
    private const OUTINGS_SHOWN = 5;

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

        AgendaShortcode::enqueueSignupScript();

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

        $actions = self::collectActions($userId);
        $urgent  = array_filter($actions, static fn (array $a): bool => $a['level'] === 'urgent') !== [];
        $level   = DiveLevels::forUser($userId);
        $active  = (new EligibilityPolicy())->hasActiveMembership($userId)->allowed;

        ob_start();
        ?>
        <div class="sub-dashboard alignwide">
            <?php echo Notice::fromQuery(); // déjà échappé ?>

            <header class="sub-dash-head">
                <p class="sub-dash-head__hello">
                    Bonjour <?php echo esc_html($user->first_name ?: $user->display_name); ?>
                </p>
                <p class="sub-dash-head__meta">
                    <?php if ($level !== null) : ?>
                        <span class="sub-pill"><?php echo esc_html(sprintf('Niveau %s', $level->name)); ?></span>
                    <?php endif; ?>
                    <?php if ($active) : ?>
                        <span class="sub-pill sub-pill--ok">Adhésion à jour</span>
                    <?php else : ?>
                        <span class="sub-pill sub-pill--alerte">Adhésion à régulariser</span>
                    <?php endif; ?>
                </p>
            </header>

            <?php self::renderShortcuts(); ?>

            <div class="sub-dash-grid">
                <div class="sub-dash-main">
                    <?php
                    // Une action bloquante (adhésion, certificat) passe devant les
                    // sorties : sans elle, le bouton « S'inscrire » ne mène nulle
                    // part. Sinon, on vient d'abord ici pour plonger.
                    if ($urgent) {
                        self::renderActions($actions);
                        self::renderOutings($userId);
                    } else {
                        self::renderOutings($userId);
                        self::renderActions($actions);
                    }
                    ?>
                </div>

                <aside class="sub-dash-side" aria-label="Autres rubriques">
                    <?php self::renderOrganiser($userId); ?>
                    <?php self::renderOffice(); ?>
                    <?php self::renderAppearance(); ?>
                </aside>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Réglage d'apparence (Système / Clair / Sombre), fourni par le thème.
     *
     * Sur un téléphone, l'en-tête n'a pas la place de la bascule : sans cette
     * carte, le réglage n'existait qu'au pied de chaque page, où personne ne le
     * cherche. Le bloc appartient au thème ; sans lui, la carte disparaît.
     */
    private static function renderAppearance(): void
    {
        if (!\WP_Block_Type_Registry::get_instance()->is_registered('subalcatel/apparence')) {
            return;
        }
        ?>
        <section class="sub-dash-card sub-dash-card--apparence">
            <h2 class="sub-dash-card__title">Affichage</h2>
            <p class="sub-help">Apparence du site sur cet appareil.</p>
            <?php echo do_blocks('<!-- wp:subalcatel/apparence /-->'); // rendu du bloc, échappé par le thème ?>
        </section>
        <?php
    }

    /**
     * Ce qui demande une action, et rien d'autre.
     */
    /**
     * @param list<array{level: string, title: string, detail: string, action: string, url: string}> $actions
     */
    private static function renderActions(array $actions): void
    {
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
        <section class="sub-dash-card sub-actions">
            <h2 class="sub-dash-card__title">
                <?php echo count($actions) === 1 ? 'Une chose à faire' : 'À faire'; ?>
            </h2>

            <?php foreach ($actions as $action) : ?>
                <article class="sub-actions__item sub-actions__item--<?php echo esc_attr($action['level']); ?>">
                    <p class="sub-actions__what"><?php echo esc_html($action['title']); ?></p>
                    <p class="sub-actions__why"><?php echo esc_html($action['detail']); ?></p>
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
            $until   = (string) get_user_meta($userId, 'sub_membership_valid_until', true);
            $days    = self::daysUntil($until);
            $renewal = (new ApplicationService())->renewalCampaign($userId);

            // La campagne suivante ouvre bien avant l'échéance de la saison en
            // cours : attendre les 45 derniers jours laissait l'adhérent sans
            // aucun lien vers le formulaire pendant des semaines.
            if ($renewal !== null) {
                $actions[] = [
                    'level'  => 'soon',
                    'title'  => sprintf('%s : les adhésions sont ouvertes', (string) $renewal['title']),
                    'detail' => $until !== ''
                        ? sprintf('Votre adhésion actuelle reste valable jusqu’au %s.', self::frDate($until))
                        : 'Votre adhésion actuelle reste valable jusqu’à son échéance.',
                    'action' => 'Renouveler',
                    'url'    => Pages::url(Pages::SUBSCRIBE),
                ];
            } elseif ($days !== null && $days <= 45) {
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

    /**
     * Les prochaines sorties du club, et de quoi s'y inscrire.
     *
     * Toutes celles que ce membre peut voir, et non celles seulement où il est
     * admissible : un membre dont le certificat a expiré disparaissait de
     * toutes les sorties sans savoir qu'il y en avait. Il les voit désormais,
     * chacune avec le motif qui l'en écarte — et la rubrique « À faire », juste
     * dessous, avec le moyen de le lever.
     *
     * Le formulaire est celui de l'agenda ({@see AgendaShortcode::renderSignup()}),
     * pour qu'une sortie ne s'inscrive pas de deux façons selon l'écran.
     */
    private static function renderOutings(int $userId): void
    {
        $service  = new EventService();
        $upcoming = $service->upcoming(20, $userId);
        $shown    = array_slice($upcoming, 0, self::OUTINGS_SHOWN);
        $agenda   = Pages::url(Pages::AGENDA);
        ?>
        <section class="sub-dash-card sub-outings">
            <h2 class="sub-dash-card__title">Sorties prévues</h2>

            <?php if ($shown === []) : ?>
                <p class="sub-help">
                    Aucune sortie programmée pour l’instant. Le calendrier se remplit
                    vite en saison : revenez bientôt.
                </p>
            <?php else : ?>
                <ul class="sub-list">
                    <?php foreach ($shown as $event) : ?>
                        <?php
                        $eventId  = (int) $event['id'];
                        $capacity = (int) $event['capacity'];
                        ?>
                        <li class="sub-list__item sub-outings__item">
                            <span class="sub-list__main">
                                <strong><?php echo esc_html((string) $event['title']); ?></strong><br>
                                <?php echo esc_html(self::frDateTime((string) $event['starts_at'])); ?>
                                <?php if (!empty($event['location'])) : ?>
                                    — <?php echo esc_html((string) $event['location']); ?>
                                <?php endif; ?>
                                <?php if ($capacity > 0) : ?>
                                    <br><small><?php echo esc_html(sprintf(
                                        '%d place(s) sur %d',
                                        $service->confirmedCount($eventId),
                                        $capacity
                                    )); ?></small>
                                <?php endif; ?>
                            </span>
                            <div class="sub-outings__signup">
                                <?php AgendaShortcode::renderSignup($service, $event, $userId); ?>
                            </div>
                            <?php EventDetails::render($service, $event); ?>
                            <?php ParticipantsList::render($service, $eventId, $userId); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($agenda !== '') : ?>
                <p class="sub-block__links">
                    <a href="<?php echo esc_url($agenda); ?>">
                        <?php echo count($upcoming) > count($shown)
                            ? esc_html(sprintf('Toutes les sorties (%d de plus)', count($upcoming) - count($shown)))
                            : 'Tout l’agenda'; ?>
                    </a>
                </p>
            <?php endif; ?>
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
        <section class="sub-dash-card">
            <h2 class="sub-dash-card__title">Vous encadrez</h2>

            <?php if ($mayCreate) : ?>
                <p class="sub-help">
                    Votre niveau vous permet de proposer une sortie au club. Elle paraît
                    dans l’agenda dès sa publication.
                </p>
            <?php endif; ?>

            <p class="sub-organiser__links">
                <?php if ($mayCreate) : ?>
                    <a class="sub-button sub-button--small sub-button--ghost" href="<?php echo esc_url(Pages::url(Pages::NEW_OUTING)); ?>">
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
     * L'entrée du bureau, pour qui exerce une responsabilité au club.
     *
     * La barre d'administration de WordPress porte déjà ce lien, mais elle se
     * masque depuis le profil, se réduit à peu de chose sur un téléphone, et
     * personne ne pense à la chercher. Retenir « /wp-admin/ » n'est pas
     * davantage une réponse : c'est une adresse que la moitié du bureau ne
     * connaît pas, et qui n'a aucune raison de s'apprendre.
     *
     * Le critère n'est pas un rôle nommé : le trésorier, le secrétariat et le
     * responsable des sorties ne partagent aucune capacité, et chacun doit
     * trouver la porte. Voir {@see self::isOfficeMember()} pour ce qu'il
     * écarte.
     */
    private static function renderOffice(): void
    {
        if (!self::isOfficeMember(get_current_user_id())) {
            return;
        }
        ?>
        <section class="sub-dash-card">
            <h2 class="sub-dash-card__title">Vous êtes au bureau</h2>

            <p class="sub-help">
                Dossiers d’adhésion, annuaire, événements, exports : la gestion du club
                se fait dans l’administration. Vous n’y trouverez que ce que vos
                responsabilités permettent.
            </p>

            <p class="sub-block__links">
                <a class="sub-button sub-button--small sub-button--ghost"
                   href="<?php echo esc_url(admin_url('admin.php?page=' . ClubMenu::SLUG)); ?>">
                    Administration du club
                </a>
            </p>
        </section>
        <?php
    }

    /**
     * Cette personne exerce-t-elle une responsabilité au bureau ?
     *
     * Plus étroit que {@see ClubMenu::hasAnyClubCapability()}, et
     * délibérément : un P3 autonome détient `sub_create_exploration_event`,
     * non parce qu'il siège au bureau mais parce qu'il a le niveau. Lui
     * annoncer « vous êtes au bureau » serait faux, et l'envoyer dans
     * l'administration serait un détour — la rubrique « Vous encadrez » du
     * même tableau de bord lui ouvre déjà sa sortie, en façade.
     *
     * On écarte donc les capacités que son niveau lui vaut, et on regarde ce
     * qui reste.
     */
    private static function isOfficeMember(int $userId): bool
    {
        $derivees = DerivedCapabilities::forUser($userId);

        foreach (array_keys(Roles::CAPABILITIES) as $capacite) {
            if (!isset($derivees[$capacite]) && user_can($userId, $capacite)) {
                return true;
            }
        }

        return false;
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

    /**
     * Les rubriques de l'espace, en tête et en tuiles : c'est la navigation de
     * l'espace membre. Reléguées en liens au pied du tableau de bord, elles
     * passaient pour une note de bas de page.
     */
    private static function renderShortcuts(): void
    {
        $links = [
            Pages::AGENDA        => ['Agenda du club', 'agenda'],
            Pages::MEMBERSHIP    => ['Mon adhésion', 'adhesion'],
            Pages::REGISTRATIONS => ['Mes inscriptions', 'inscriptions'],
            Pages::MY_DOCUMENTS  => ['Mes documents', 'documents'],
            Pages::PROFILE       => ['Mon profil', 'profil'],
        ];
        ?>
        <nav class="sub-shortcuts" aria-label="Rubriques de l’espace membre">
            <?php foreach ($links as $slug => [$label, $icon]) : ?>
                <?php $url = Pages::url($slug); ?>
                <?php if ($url !== '') : ?>
                    <a class="sub-shortcuts__item sub-shortcuts__item--<?php echo esc_attr($icon); ?>"
                       href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php
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
