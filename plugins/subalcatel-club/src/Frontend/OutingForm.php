<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Identity\AccountApproval;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Policy\EligibilityPolicy;

/**
 * Organiser une sortie : shortcode [subalcatel_creer_sortie].
 *
 * Un directeur de plongée ou un plongeur autonome propose une sortie depuis
 * l'espace membre, sans passer par l'administration du site. C'est le pendant
 * de l'écran Club → Événements, réduit à ce qu'un organisateur remplit
 * réellement, et rendu dans la charte du site plutôt que dans celle de
 * WordPress — un P3 qui ouvre une exploration n'a rien à faire dans le
 * tableau de bord technique.
 *
 * Aucune règle n'est réécrite ici : les types proposés, les droits et la
 * création passent tous par [EventService]. Ce fichier est une IHM.
 */
final class OutingForm
{
    public static function register(): void
    {
        add_shortcode('subalcatel_creer_sortie', [self::class, 'render']);
        add_action('admin_post_sub_outing_create', [self::class, 'handleCreate']);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="sub-notice"><strong>Espace réservé aux membres</strong>'
                . '<p><a href="%s">Se connecter</a></p></div>',
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

        if (AccountApproval::isPending($userId) || AccountApproval::isRefused($userId)) {
            return '<div class="sub-notice sub-notice--info"><p>Votre compte doit être validé '
                . 'par le bureau avant de pouvoir organiser une sortie.</p></div>';
        }

        $types = (new EventService())->creatableTypesFor($userId);

        ob_start();

        foreach (['sub_done' => 'success', 'sub_error' => 'error'] as $key => $class) {
            if (isset($_GET[$key])) {
                printf(
                    '<div class="sub-notice sub-notice--%s"><p>%s</p></div>',
                    esc_attr($class),
                    esc_html(sanitize_text_field(wp_unslash((string) $_GET[$key])))
                );
            }
        }

        if ($types === []) {
            self::renderRefusal($userId);

            return (string) ob_get_clean();
        }

        self::renderForm($types);

        return (string) ob_get_clean();
    }

    /**
     * Pourquoi ce membre ne peut rien créer.
     *
     * Un « vous n'avez pas le droit » sec produit un appel téléphonique au
     * président. On nomme donc la condition qui manque, et elle seule.
     */
    private static function renderRefusal(int $userId): void
    {
        $policy = new EligibilityPolicy();
        $level  = DiveLevels::forUser($userId);

        $reason = match (true) {
            !$policy->hasActiveMembership($userId)->allowed =>
                'Votre adhésion n’est pas à jour. Organiser une sortie engage le club : '
                . 'le droit revient dès le renouvellement.',
            $level === null =>
                'Votre niveau de plongée n’est pas encore renseigné sur votre profil. '
                . 'Le bureau le saisit à partir de votre licence.',
            default => sprintf(
                'Votre niveau (%s) n’ouvre pas ce droit : une exploration se propose à partir '
                . 'du niveau autonome, une formation par un encadrant.',
                $level->name
            ),
        };
        ?>
        <div class="sub-notice sub-notice--info">
            <p><strong>Vous ne pouvez pas encore proposer de sortie.</strong></p>
            <p><?php echo esc_html($reason); ?></p>
            <?php if (Pages::exists(Pages::AGENDA)) : ?>
                <p><a href="<?php echo esc_url(Pages::url(Pages::AGENDA)); ?>">Voir l’agenda du club</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param list<array<string, mixed>> $types
     */
    private static function renderForm(array $types): void
    {
        $levels   = DiveLevels::ordered();
        $divers   = array_filter($levels, static fn ($l): bool => DiveLevels::rankOf($l->term_id, DiveLevels::RANK_TEACHING) === 0
            && DiveLevels::rankOf($l->term_id, DiveLevels::RANK_DIVER) > 0);
        $teachers = array_filter($levels, static fn ($l): bool => DiveLevels::rankOf($l->term_id, DiveLevels::RANK_TEACHING) > 0);

        // Une sortie se prépare : la date par défaut est le week-end prochain,
        // pas maintenant. Le champ reste modifiable, c'est une suggestion.
        $default = wp_date('Y-m-d\TH:i', strtotime('next saturday 9:00'));
        ?>
        <form class="sub-outing" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sub_outing_create">
            <?php wp_nonce_field('sub_outing_create'); ?>

            <fieldset class="sub-field">
                <legend>La sortie</legend>
                <div class="sub-grid">
                    <p class="sub-input">
                        <label for="sub_outing_type">Type de sortie</label>
                        <select id="sub_outing_type" name="type_slug" required>
                            <?php foreach ($types as $type) : ?>
                                <option value="<?php echo esc_attr((string) $type['slug']); ?>">
                                    <?php echo esc_html((string) $type['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sub-input__help">
                            Seuls les types que votre niveau vous autorise à proposer sont listés.
                        </span>
                    </p>
                    <p class="sub-input">
                        <label for="sub_outing_title">Titre</label>
                        <input type="text" id="sub_outing_title" name="title" required
                               maxlength="150" placeholder="Exploration au Squewel">
                    </p>
                    <p class="sub-input sub-input--wide">
                        <label for="sub_outing_location">Lieu de rendez-vous</label>
                        <input type="text" id="sub_outing_location" name="location"
                               maxlength="150" placeholder="Ploumanac’h — parking de Pors Kamor">
                    </p>
                </div>
            </fieldset>

            <fieldset class="sub-field">
                <legend>Quand</legend>
                <div class="sub-grid">
                    <p class="sub-input">
                        <label for="sub_outing_start">Départ</label>
                        <input type="datetime-local" id="sub_outing_start" name="starts_at" required
                               value="<?php echo esc_attr($default); ?>">
                    </p>
                    <p class="sub-input">
                        <label for="sub_outing_end">Retour</label>
                        <input type="datetime-local" id="sub_outing_end" name="ends_at">
                        <span class="sub-input__help">À remplir pour une sortie sur plusieurs jours.</span>
                    </p>
                    <p class="sub-input">
                        <label for="sub_outing_closes">Clôture des inscriptions</label>
                        <input type="datetime-local" id="sub_outing_closes" name="registration_closes_at">
                        <span class="sub-input__help">Par défaut, ouvertes jusqu’au départ.</span>
                    </p>
                </div>
            </fieldset>

            <fieldset class="sub-field">
                <legend>Qui peut venir</legend>
                <div class="sub-grid">
                    <p class="sub-input">
                        <label for="sub_outing_capacity">Nombre de places</label>
                        <input type="number" id="sub_outing_capacity" name="capacity"
                               min="0" max="200" value="0" inputmode="numeric">
                        <span class="sub-input__help">
                            0 = pas de limite. Au-delà, les suivants passent en liste d’attente.
                        </span>
                    </p>
                    <p class="sub-input">
                        <label for="sub_outing_level">Niveau plongeur minimum</label>
                        <select id="sub_outing_level" name="accepted_levels[]">
                            <option value="">aucune exigence</option>
                            <?php foreach ($divers as $level) : ?>
                                <option value="<?php echo esc_attr($level->slug); ?>">
                                    <?php echo esc_html($level->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sub-input__help">
                            Les niveaux supérieurs sont admis d’office : ouvrir au P2 ouvre aussi aux P3, P4 et P5.
                        </span>
                    </p>
                    <p class="sub-input">
                        <label for="sub_outing_teaching">Niveau d’encadrement minimum</label>
                        <select id="sub_outing_teaching" name="accepted_levels[]">
                            <option value="">aucune exigence</option>
                            <?php foreach ($teachers as $level) : ?>
                                <option value="<?php echo esc_attr($level->slug); ?>">
                                    <?php echo esc_html($level->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sub-input__help">
                            À ne remplir que pour une sortie réservée aux encadrants.
                        </span>
                    </p>
                </div>
            </fieldset>

            <fieldset class="sub-field">
                <legend>Détails</legend>
                <p class="sub-input">
                    <label for="sub_outing_description">Description</label>
                    <textarea id="sub_outing_description" name="description" rows="5"
                              placeholder="Site, profondeur prévue, covoiturage, matériel à prévoir…"></textarea>
                </p>
            </fieldset>

            <fieldset class="sub-field">
                <legend>Prévenir les membres</legend>
                <p class="sub-input">
                    <label class="sub-check" for="sub_outing_announce">
                        <input type="checkbox" id="sub_outing_announce" name="announce" value="1">
                        Envoyer une annonce par courriel aux membres qui peuvent s’inscrire
                    </label>
                    <span class="sub-input__help">
                        Rien ne part si la case reste décochée. Vous pourrez aussi envoyer
                        l’annonce plus tard, depuis « Mes sorties organisées ». Les adhérents
                        qui ont refusé les annonces sur leur profil n’en reçoivent jamais.
                    </span>
                </p>
            </fieldset>

            <p class="sub-outing__submit">
                <button class="sub-button">Publier la sortie</button>
            </p>

            <p class="sub-help">
                La sortie apparaît immédiatement dans l’agenda du club, ouverte aux inscriptions.
                Vous en êtes l’organisateur : vous pouvez écrire aux inscrits et retirer quelqu’un
                de la liste.
            </p>
        </form>
        <?php
    }

    public static function handleCreate(): void
    {
        if (!is_user_logged_in() || !check_admin_referer('sub_outing_create')) {
            wp_die('Requête non autorisée.', 403);
        }

        $data = [
            'title'                  => sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? ''))),
            'location'               => sanitize_text_field(wp_unslash((string) ($_POST['location'] ?? ''))),
            // Pas de `wp_kses_post` ici : la description d'une sortie saisie
            // depuis le site public n'a aucune raison de porter du HTML.
            'description'            => sanitize_textarea_field(wp_unslash((string) ($_POST['description'] ?? ''))),
            'starts_at'              => self::datetime($_POST['starts_at'] ?? ''),
            'ends_at'                => self::datetime($_POST['ends_at'] ?? '') ?: null,
            'registration_closes_at' => self::datetime($_POST['registration_closes_at'] ?? '') ?: null,
            'capacity'               => min(200, absint($_POST['capacity'] ?? 0)),
            // Les deux listes envoient une valeur vide quand rien n'est exigé :
            // la garder ferait un plancher fantôme dans la comparaison.
            'accepted_levels'        => array_values(array_filter(
                array_map('sanitize_key', (array) ($_POST['accepted_levels'] ?? []))
            )),
        ];

        if ($data['title'] === '' || $data['starts_at'] === '') {
            self::back('Le titre et la date de départ sont obligatoires.', true);
        }

        // Une sortie se propose avant d'avoir lieu. L'administration autorise la
        // saisie rétroactive — un compte rendu, une régularisation — mais depuis
        // l'espace membre, une date passée est toujours une faute de frappe.
        if ($data['starts_at'] < current_time('mysql')) {
            self::back('La date de départ est déjà passée.', true);
        }

        $service = new EventService();

        try {
            $eventId = $service->create(
                sanitize_key(wp_unslash((string) ($_POST['type_slug'] ?? ''))),
                $data,
                get_current_user_id()
            );
        } catch (\RuntimeException $e) {
            self::back($e->getMessage(), true);
        }

        $message = 'Sortie publiée. Elle est ouverte aux inscriptions.';
        $isError = false;

        if (isset($_POST['announce'])) {
            // La sortie, elle, est publiée : un échec d'annonce ne doit pas la
            // laisser croire perdue. L'organisateur pourra réessayer depuis
            // « Mes sorties organisées ».
            try {
                $result = $service->announce($eventId, get_current_user_id());

                $message .= $result['sent'] === $result['recipients']
                    ? sprintf(' Annonce envoyée à %d membre(s).', $result['sent'])
                    : sprintf(
                        ' Annonce partielle : %d message(s) parti(s) sur %d — prévenez le bureau.',
                        $result['sent'],
                        $result['recipients']
                    );

                $isError = $result['sent'] < $result['recipients'];
            } catch (\RuntimeException $e) {
                $message .= ' En revanche, l’annonce n’est pas partie : ' . $e->getMessage();
                $isError  = true;
            }
        }

        // Vers l'agenda : l'organisateur voit sa sortie publiée, à sa place,
        // telle que les autres la liront.
        wp_safe_redirect(add_query_arg(
            [$isError ? 'sub_error' : 'sub_done' => rawurlencode($message)],
            Pages::url(Pages::AGENDA) ?: (wp_get_referer() ?: home_url('/'))
        ));
        exit;
    }

    private static function back(string $message, bool $isError = false): void
    {
        wp_safe_redirect(add_query_arg(
            [$isError ? 'sub_error' : 'sub_done' => rawurlencode($message)],
            wp_get_referer() ?: home_url('/')
        ));
        exit;
    }

    private static function datetime(mixed $raw): string
    {
        $value = sanitize_text_field(wp_unslash((string) $raw));

        if ($value === '') {
            return '';
        }

        $ts = strtotime($value);

        return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
    }
}
