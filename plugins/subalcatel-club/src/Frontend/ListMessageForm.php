<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Communication\ListMessages;
use Subalcatel\Club\Notifications\MailQueue;

/**
 * Écrire au club : shortcode [subalcatel_ecrire_au_club].
 *
 * Le formulaire d'un adhérent vers une liste de diffusion. Les règles — qui
 * peut écrire, à quelle liste, combien de fois — vivent dans [ListMessages] ;
 * ce fichier les affiche et ne les redit pas.
 *
 * Le nombre de destinataires figure à côté de chaque liste et dans la
 * confirmation : écrire à cent trente personnes ne doit pas être un geste
 * distrait, et « Tout le club » ne dit pas combien c'est. Le bureau voit en
 * plus leurs noms, repliés sous chaque liste.
 */
final class ListMessageForm
{
    private const ACTION = 'sub_list_message';

    /** Brouillon rendu au formulaire après un refus, le temps de corriger. */
    private const DRAFT = 'sub_list_message_draft_';

    public static function register(): void
    {
        add_shortcode('subalcatel_ecrire_au_club', [self::class, 'render']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
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

        ob_start();

        echo Notice::fromQuery(); // déjà échappé

        if (!ListMessages::canSend($userId)) {
            printf(
                '<div class="sub-notice"><strong>Réservé aux adhérents à jour</strong>'
                . '<p>Les listes du club sont ouvertes aux membres dont l’adhésion est valide. %s</p></div>',
                Pages::exists(Pages::MEMBERSHIP)
                    ? sprintf('<a href="%s">Voir mon adhésion</a>.', esc_url(Pages::url(Pages::MEMBERSHIP)))
                    : ''
            );

            return (string) ob_get_clean();
        }

        $remaining = ListMessages::remainingToday($userId);
        $showNames = ListMessages::canSeeRecipients($userId);
        $draft     = (array) get_transient(self::DRAFT . $userId) + ['liste' => '', 'subject' => '', 'message' => ''];
        $lists     = [];

        delete_transient(self::DRAFT . $userId);

        foreach (ListMessages::listsFor($userId) as $list) {
            $ids     = ListMessages::recipients($list['slug'], $userId);
            $lists[] = $list + ['count' => count($ids), 'ids' => $ids];
        }

        // Cochée d'avance : la liste demandée par le lien ou le brouillon, à
        // défaut la première qui compte quelqu'un.
        $selected = isset($_GET['liste']) ? sanitize_key(wp_unslash($_GET['liste'])) : (string) $draft['liste'];

        if (!in_array($selected, array_column(array_filter($lists, static fn (array $l): bool => $l['count'] > 0), 'slug'), true)) {
            $selected = '';

            foreach ($lists as $list) {
                if ($list['count'] > 0) {
                    $selected = $list['slug'];
                    break;
                }
            }
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              class="sub-outing sub-list-message"
              onsubmit="var c=this.querySelector('input[name=liste]:checked');return !c||confirm('Envoyer ce message à '+c.dataset.count+' personne(s) ?');">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <?php wp_nonce_field(self::ACTION); ?>

            <fieldset class="sub-field">
                <legend class="sub-field__label">À qui écrivez-vous ?</legend>

                <?php foreach ($lists as $list) : ?>
                    <?php $count = $list['count']; ?>
                    <label class="sub-choice">
                        <input type="radio" name="liste" required
                               value="<?php echo esc_attr($list['slug']); ?>"
                               data-count="<?php echo esc_attr((string) $count); ?>"
                               <?php checked($selected === $list['slug']); ?>
                               <?php disabled($count === 0); ?>>
                        <span class="sub-choice__label">
                            <strong><?php echo esc_html($list['label']); ?></strong><br>
                            <span class="sub-help"><?php echo esc_html($list['help']); ?></span>
                        </span>
                        <span class="sub-choice__price">
                            <?php printf('%d destinataire%s', (int) $count, $count > 1 ? 's' : ''); ?>
                        </span>
                    </label>

                    <?php // Hors du <label> : déplier la liste ne doit pas cocher la case. ?>
                    <?php if ($showNames && $count > 0) : ?>
                        <details class="sub-list-message__recipients">
                            <summary>
                                <?php printf('Voir les %d destinataire%s', (int) $count, $count > 1 ? 's' : ''); ?>
                            </summary>
                            <ul>
                                <?php foreach (ListMessages::recipientNames($list['ids']) as $name) : ?>
                                    <li><?php echo esc_html($name); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                <?php endforeach; ?>
            </fieldset>

            <p class="sub-input">
                <label for="sub_list_subject">Objet</label>
                <input type="text" id="sub_list_subject" name="subject" required maxlength="150"
                       value="<?php echo esc_attr((string) $draft['subject']); ?>"
                       placeholder="Plongée dimanche matin à la Pointe du Diable — qui vient ?">
            </p>
            <p class="sub-input">
                <label for="sub_list_message">Message</label>
                <textarea id="sub_list_message" name="message" rows="8" required><?php
                    echo esc_textarea((string) $draft['message']); ?></textarea>
            </p>

            <p class="sub-outing__submit">
                <button class="sub-button" <?php disabled($remaining === 0); ?>>Envoyer</button>
            </p>

            <p class="sub-help">
                Les destinataires ne voient ni votre adresse ni celles des autres. Ils vous
                répondent directement, d’un simple « Répondre » : la réponse ne repart pas à
                toute la liste. Les membres qui ont refusé les communications du club sur leur
                profil ne reçoivent pas ces messages, et l’envoi est tracé dans le journal du site.
            </p>

            <?php if ($remaining !== null) : ?>
                <p class="sub-help">
                    <?php echo $remaining === 0
                        ? esc_html(sprintf(
                            'Vous avez envoyé vos %d messages du jour : le formulaire rouvre demain.',
                            ListMessages::DAILY_LIMIT
                        ))
                        : esc_html(sprintf(
                            'Jusqu’à %d messages par jour et par adhérent — il vous en reste %d aujourd’hui.',
                            ListMessages::DAILY_LIMIT,
                            $remaining
                        )); ?>
                </p>
            <?php endif; ?>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    public static function handle(): void
    {
        if (!is_user_logged_in() || !check_admin_referer(self::ACTION)) {
            wp_die('Requête non autorisée.', 403);
        }

        $userId  = get_current_user_id();
        $slug    = sanitize_key(wp_unslash((string) ($_POST['liste'] ?? '')));
        $subject = sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? '')));
        $message = sanitize_textarea_field(wp_unslash((string) ($_POST['message'] ?? '')));

        try {
            $result = ListMessages::send($slug, $subject, $message, $userId);
        } catch (\RuntimeException $e) {
            // Un refus ne doit pas coûter le texte : on le rend au formulaire.
            set_transient(self::DRAFT . $userId, ['liste' => $slug, 'subject' => $subject, 'message' => $message], 15 * MINUTE_IN_SECONDS);
            self::back($e->getMessage(), true);
        }

        $failed = $result['recipients'] - $result['sent'] - $result['queued'];

        self::back(
            $failed === 0
                ? trim(($result['sent'] > 0 ? sprintf('Message envoyé à %d personne(s).', $result['sent']) : '')
                    . MailQueue::outcomeNote($result['queued']))
                : sprintf(
                    'Envoi partiel : %d message(s) parti(s) sur %d. Prévenez le bureau.',
                    $result['sent'],
                    $result['recipients']
                ),
            $failed > 0
        );
    }

    private static function back(string $message, bool $isError = false): never
    {
        $base = remove_query_arg(['sub_done', 'sub_error', 'liste'], wp_get_referer() ?: Pages::url(Pages::WRITE_TO_CLUB));

        wp_safe_redirect(add_query_arg($isError ? 'sub_error' : 'sub_done', rawurlencode($message), $base));
        exit;
    }
}
