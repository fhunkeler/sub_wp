<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Notifications\DailyDigest;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Support\Audit;

/**
 * Courriels : modèles éditables et journal des envois.
 *
 * Les textes appartiennent au bureau, pas au code. Un message mal tourné se
 * corrige en trente secondes, sans développeur et sans risque de casser l'envoi.
 */
final class NotificationsScreen
{
    /** Onglets de {@see CommunicationScreen}. */
    public const TAB_TEMPLATES = 'modeles';
    public const TAB_LOG       = 'journal';

    public static function register(): void
    {
        add_action('admin_post_sub_template_save', [self::class, 'handleSave']);
        add_action('admin_post_sub_template_preview', [self::class, 'handlePreview']);
        add_action('admin_post_sub_daily_run', [self::class, 'handleRunDaily']);
    }

    public static function renderTemplates(): void
    {
        AdminUi::requireCap('sub_manage_memberships');

        $channels = [
            EmailTemplates::CHANNEL_TRANSACTIONAL => 'Automatique',
            EmailTemplates::CHANNEL_TARGETED      => 'Envoyé par une personne',
        ];
        ?>
        <p class="description">
            Les variables entre accolades sont remplacées à l’envoi. Une variable mal
            orthographiée reste visible dans le message reçu — c’est voulu : un
            <code>{montant}</code> qui apparaît se remarque et se corrige, un blanc passe inaperçu.
        </p>

        <?php foreach (EmailTemplates::all() as $template) : ?>
            <?php
            $variables = (array) (json_decode((string) $template['variables'], true) ?: []);
            $variables = array_merge(EmailTemplates::commonVariables(), $variables);
            ?>
            <details class="sub-card">
                <summary>
                    <strong><?php echo esc_html((string) $template['label']); ?></strong>
                    <code><?php echo esc_html((string) $template['code']); ?></code>
                    <span class="sub-tag"><?php echo esc_html($channels[$template['channel']] ?? ''); ?></span>
                    <?php if ((int) $template['published'] !== 1) : ?>
                        <span class="sub-tag sub-tag--off">désactivé</span>
                    <?php endif; ?>
                </summary>

                <p class="description"><?php echo esc_html((string) $template['description']); ?></p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
                    <input type="hidden" name="action" value="sub_template_save">
                    <input type="hidden" name="code" value="<?php echo esc_attr((string) $template['code']); ?>">
                    <?php wp_nonce_field('sub_template_save'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Objet</th>
                            <td>
                                <input type="text" name="subject" class="large-text" required
                                       value="<?php echo esc_attr((string) $template['subject']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Message</th>
                            <td>
                                <textarea name="body" rows="12" class="large-text" required
                                          style="font-family:monospace;"><?php echo esc_textarea((string) $template['body']); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Variables disponibles</th>
                            <td>
                                <div class="sub-variables">
                                    <?php foreach ($variables as $name => $description) : ?>
                                        <span><code>{<?php echo esc_html((string) $name); ?>}</code>
                                              <?php echo esc_html((string) $description); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Adhérents mineurs</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="copy_guardian" value="1"
                                           <?php checked((int) ($template['copy_guardian'] ?? 0), 1); ?>>
                                    Envoyer aussi une copie au représentant légal
                                </label>
                                <p class="description">
                                    Sans effet pour les majeurs. Utile pour les rappels : un adolescent
                                    ne renouvellera pas seul son certificat médical.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Actif</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="published" value="1"
                                           <?php checked((int) $template['published'], 1); ?>>
                                    Envoyer ce message
                                </label>
                                <p class="description">
                                    Décoché, l’envoi est simplement supprimé — le reste du parcours
                                    continue de fonctionner.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit"><button class="button button-primary">Enregistrer</button></p>
                </form>

                <?php // Frère du formulaire d'édition, jamais enfant. ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      style="display:flex;gap:8px;align-items:center;margin-bottom:16px;">
                    <input type="hidden" name="action" value="sub_template_preview">
                    <input type="hidden" name="code" value="<?php echo esc_attr((string) $template['code']); ?>">
                    <?php wp_nonce_field('sub_template_preview'); ?>
                    <input type="email" name="email" placeholder="votre@courriel"
                           value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required>
                    <button class="button">M’envoyer un aperçu</button>
                </form>
            </details>
        <?php endforeach; ?>
        <?php
    }

    public static function renderLog(): void
    {
        AdminUi::requireCap('sub_manage_memberships');

        $entries = Mailer::recent(150);
        ?>
        <p class="description">
            Ce qui est réellement parti. Sans cette trace, personne ne sait si l’information
            a été transmise — et le bureau finit par tout renvoyer « au cas où ».
        </p>

        <p>
            <?php AdminUi::actionButton(
                'sub_daily_run',
                [],
                'Lancer les rappels maintenant',
                'button',
                'Exécuter immédiatement la tâche quotidienne ? Des courriels seront envoyés.'
            ); ?>
        </p>

        <table class="wp-list-table widefat striped sub-cards">
            <thead>
                <tr>
                    <th style="width:140px;">Quand</th>
                    <th style="width:200px;">Destinataire</th>
                    <th>Objet</th>
                    <th style="width:160px;">Modèle</th>
                    <th style="width:120px;">Envoyé par</th>
                    <th style="width:90px;">État</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($entries === []) : ?>
                <tr><td colspan="6">Aucun envoi pour l’instant.</td></tr>
            <?php endif; ?>

            <?php foreach ($entries as $entry) : ?>
                <?php $sender = $entry['sender_id'] ? get_userdata((int) $entry['sender_id']) : null; ?>
                <tr>
                    <td data-label="Quand">
                        <?php echo esc_html(wp_date('d/m/Y H:i', (int) strtotime((string) $entry['sent_at']))); ?>
                    </td>
                    <td data-label="Destinataire">
                        <?php // L'adresse part avec le compte supprimé ; la trace de l'envoi reste. ?>
                        <?php echo $entry['recipient_email'] === ''
                            ? '<em style="color:#50575e;">Compte supprimé</em>'
                            : esc_html((string) $entry['recipient_email']); ?>
                    </td>
                    <td data-label="Objet"><?php echo esc_html((string) $entry['subject']); ?></td>
                    <td data-label="Modèle"><code><?php echo esc_html((string) $entry['template_code']); ?></code></td>
                    <td data-label="Envoyé par">
                        <?php echo esc_html($sender?->display_name ?? 'Automatique'); ?>
                    </td>
                    <td data-label="État">
                        <?php echo $entry['status'] === 'sent'
                            ? AdminUi::statusBadge('active')
                            : AdminUi::statusBadge('refused'); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Retour sur l'onglet d'où venait le formulaire.
     */
    private static function back(string $message, bool $isError = false, string $tab = self::TAB_TEMPLATES): never
    {
        AdminUi::redirect(CommunicationScreen::SLUG, $message, $isError, ['tab' => $tab]);
    }

    public static function handleSave(): void
    {
        check_admin_referer('sub_template_save');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $code    = sanitize_text_field(wp_unslash((string) ($_POST['code'] ?? '')));
        $subject = sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? '')));
        $body    = sanitize_textarea_field(wp_unslash((string) ($_POST['body'] ?? '')));

        if ($subject === '' || $body === '') {
            self::back('L’objet et le message sont obligatoires.', true);
        }

        $wpdb->update("{$wpdb->prefix}sub_email_templates", [
            'subject'    => $subject,
            'body'       => $body,
            'published'     => isset($_POST['published']) ? 1 : 0,
            'copy_guardian' => isset($_POST['copy_guardian']) ? 1 : 0,
            'updated_at' => current_time('mysql'),
        ], ['code' => $code]);

        Audit::log('email_template.saved', 'email_template', null, ['code' => $code]);

        self::back('Modèle enregistré.');
    }

    /**
     * Envoie un aperçu à soi-même, avec des valeurs d'exemple.
     *
     * Relire un modèle dans un champ de saisie ne dit rien de ce que recevra le
     * membre. Le voir arriver dans sa boîte, si.
     */
    public static function handlePreview(): void
    {
        check_admin_referer('sub_template_preview');
        AdminUi::requireCap('sub_manage_memberships');

        $code  = sanitize_text_field(wp_unslash((string) ($_POST['code'] ?? '')));
        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));

        if (!is_email($email)) {
            self::back('Adresse de courriel invalide.', true);
        }

        $template = EmailTemplates::find($code);

        if ($template === null) {
            self::back('Modèle introuvable.', true);
        }

        // Valeurs d'exemple : le bureau doit voir un message crédible, pas des
        // accolades vides.
        $samples = [
            'reference'    => 'ADH-2026-EXEMPLE',
            'montant'      => '273,00 €',
            'formule'      => 'Plongée',
            'mode'         => 'chèque',
            'fin_validite' => wp_date('j F Y', strtotime('+1 year')),
            'jours'        => '30',
            'motif'        => 'Exemple de motif renseigné par le bureau.',
            'document'     => 'certificat médical',
            'date_purge'   => wp_date('j F Y', strtotime('+13 months')),
            'evenement'    => 'Exploration au Squewel',
            'date'         => wp_date('l j F Y à H\\hi', strtotime('+10 days')),
            'lieu'         => 'Ploumanac’h — Pors Kamor',
            'position'     => '2',
            'objet'        => 'Rendez-vous 8h',
            'message'      => 'Départ du parking à 8h précises.',
            'expediteur'   => wp_get_current_user()->display_name,
        ];

        $sent = Mailer::send($code, $email, $samples, [
            'recipient'    => wp_get_current_user(),
            'recipient_id' => get_current_user_id(),
        ]);

        self::back(
            $sent
                ? sprintf('Aperçu envoyé à %s.', $email)
                : 'L’envoi a échoué. Vérifiez la configuration du courriel sortant.',
            !$sent
        );
    }

    public static function handleRunDaily(): void
    {
        check_admin_referer('sub_daily_run');
        AdminUi::requireCap('sub_manage_memberships');

        $result = DailyDigest::run();

        self::back(sprintf(
            'Tâche exécutée : %d document(s) expiré(s), %d purgé(s), %d rappel(s) document, '
            . '%d avertissement(s) de suppression, %d rappel(s) d’adhésion.',
            $result['expired'],
            $result['purged'],
            $result['doc_reminders'],
            $result['purge_warnings'],
            $result['membership_reminders']
        ), false, self::TAB_LOG);
    }
}
