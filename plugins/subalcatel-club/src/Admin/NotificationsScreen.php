<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Notifications\DailyDigest;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\MailQueue;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Notifications\SendQuota;
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
        add_action('admin_post_sub_mail_quota_save', [self::class, 'handleQuotaSave']);
        add_action('admin_post_sub_mail_queue_cancel', [self::class, 'handleQueueCancel']);
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

        <?php self::renderQuota(); ?>

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
                        <?php echo AdminUi::localTime($entry['sent_at']); ?>
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
                        <?php echo AdminUi::statusBadge(match ($entry['status']) {
                            'sent'     => 'active',
                            'deferred' => 'deferred',
                            default    => 'refused',
                        }); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Compteur du jour et réglage du plafond d'envoi.
     *
     * Le plafond suit l'offre du service d'envoi : le bureau qui change d'offre
     * le relève ici, sans développeur.
     */
    private static function renderQuota(): void
    {
        $settings  = SendQuota::settings();
        $sent      = SendQuota::sentToday();
        $remaining = SendQuota::bulkRemaining();
        ?>
        <div class="sub-card">
            <h3 style="margin-top:0;">Plafond d’envoi quotidien</h3>
            <p>
                <?php if ($settings['limit'] === 0) : ?>
                    <strong><?php echo (int) $sent; ?></strong> courriel(s) envoyé(s) aujourd’hui — aucun plafond réglé.
                <?php else : ?>
                    <strong><?php echo (int) $sent; ?> / <?php echo (int) $settings['limit']; ?></strong>
                    courriels envoyés aujourd’hui. Envois groupés encore possibles :
                    <strong><?php echo (int) $remaining; ?></strong>.
                <?php endif; ?>
            </p>
            <p class="description">
                Une annonce de sortie, un message aux inscrits ou une annulation qui dépasserait le
                plafond est mis en file d’attente <strong>en entier</strong>, jamais envoyé à moitié :
                il part d’un bloc dès que le quota le permet, et il est abandonné si la sortie commence
                avant. L’annonce d’ouverture d’une campagne, elle, part par tranches sur plusieurs jours.
                Les rappels automatiques en excès sont reportés au lendemain. Les messages individuels
                (mot de passe, confirmation de dossier) partent toujours : la réserve leur est gardée.
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
                <input type="hidden" name="action" value="sub_mail_quota_save">
                <?php wp_nonce_field('sub_mail_quota_save'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sub-quota-limit">Plafond par jour</label></th>
                        <td>
                            <input type="number" min="0" step="1" id="sub-quota-limit" name="limit" class="small-text"
                                   value="<?php echo (int) $settings['limit']; ?>">
                            <p class="description">
                                Celui de l’offre du service d’envoi (Brevo gratuit : 300). 0 : pas de plafond.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sub-quota-reserve">Réserve</label></th>
                        <td>
                            <input type="number" min="0" step="1" id="sub-quota-reserve" name="reserve" class="small-text"
                                   value="<?php echo (int) $settings['reserve']; ?>">
                            <p class="description">
                                Part du plafond que les envois groupés ne peuvent pas entamer.
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button class="button">Enregistrer le plafond</button></p>
            </form>

            <?php self::renderQueue(); ?>
        </div>
        <?php
    }

    /**
     * Ce qui attend dans la file, et le moyen de l'arrêter.
     *
     * Une annonce mise en file un vendredi soir part le samedi : l'organisateur
     * qui a entre-temps prévenu tout le monde autrement doit pouvoir la retirer.
     */
    private static function renderQueue(): void
    {
        $jobs = MailQueue::jobs();
        ?>
        <h3>File d’attente</h3>
        <?php if ($jobs === []) : ?>
            <p class="description">Aucun envoi en attente.</p>
        <?php else : ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Envoi</th>
                        <th style="width:110px;">Reste à envoyer</th>
                        <th style="width:150px;">Mis en file</th>
                        <th style="width:150px;">Abandonné après</th>
                        <th style="width:110px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $id => $job) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html((string) $job['label']); ?>
                            <br><small><?php echo esc_html($job['atomic'] ? 'D’un bloc' : 'Par tranches'); ?>
                            <?php if ((int) $job['sent'] > 0) : ?>
                                — <?php echo (int) $job['sent']; ?> déjà parti(s)
                            <?php endif; ?></small>
                        </td>
                        <td><?php echo count((array) $job['users']); ?></td>
                        <td><?php echo esc_html(mysql2date('j F Y à H\\hi', (string) $job['queued_at'])); ?></td>
                        <td><?php echo esc_html($job['expires_at'] ? mysql2date('j F Y à H\\hi', (string) $job['expires_at']) : '—'); ?></td>
                        <td>
                            <?php AdminUi::actionButton(
                                'sub_mail_queue_cancel',
                                ['job' => (string) $id],
                                'Annuler',
                                'button button-link-delete',
                                'Retirer cet envoi de la file ? Les messages restants ne partiront pas.'
                            ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    public static function handleQueueCancel(): void
    {
        check_admin_referer('sub_mail_queue_cancel');
        AdminUi::requireCap('sub_manage_memberships');

        $done = MailQueue::cancel(sanitize_text_field(wp_unslash((string) ($_POST['job'] ?? ''))));

        self::back($done ? 'Envoi retiré de la file d’attente.' : 'Cet envoi n’est plus dans la file.', !$done, self::TAB_LOG);
    }

    public static function handleQuotaSave(): void
    {
        check_admin_referer('sub_mail_quota_save');
        AdminUi::requireCap('sub_manage_memberships');

        $limit   = absint($_POST['limit'] ?? SendQuota::DEFAULT_LIMIT);
        $reserve = absint($_POST['reserve'] ?? SendQuota::DEFAULT_RESERVE);

        if ($limit > 0 && $reserve >= $limit) {
            self::back('La réserve doit rester inférieure au plafond, sinon aucun envoi groupé ne partirait.', true, self::TAB_LOG);
        }

        SendQuota::save($limit, $reserve);
        Audit::log('mail_quota.saved', 'system', null, ['limit' => $limit, 'reserve' => $reserve]);

        self::back('Plafond d’envoi enregistré.', false, self::TAB_LOG);
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
        ) . ($result['deferred'] > 0
            ? sprintf(' %d envoi(s) reporté(s) au lendemain : plafond quotidien atteint.', $result['deferred'])
            : ''), false, self::TAB_LOG);
    }
}
