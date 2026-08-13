<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentStorage;
use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Frontend\DocumentsForm;

/**
 * Pièces justificatives des membres : file de validation, types, stockage.
 *
 * Les trois vivent désormais dans deux écrans différents, et c'est voulu. La
 * file est un travail hebdomadaire, qui a sa place auprès des personnes ; les
 * types et le stockage sont des référentiels qu'on ouvre deux fois par an, et
 * qui ont la leur dans les réglages. Ce fichier garde le code des trois : le
 * découpage est celui du menu, pas celui du métier.
 */
final class DocumentsScreen
{
    /** Onglet de {@see MembersScreen} où vit la file de validation. */
    public const TAB = 'pieces';

    /** Onglets de {@see SettingsScreen} qui portent la configuration. */
    public const TAB_TYPES   = 'types_documents';
    public const TAB_STORAGE = 'stockage';

    public static function register(): void
    {
        add_action('admin_post_sub_document_review', [self::class, 'handleReview']);
        add_action('admin_post_sub_document_type_save', [self::class, 'handleTypeSave']);
        add_action('admin_post_sub_document_type_create', [self::class, 'handleTypeCreate']);
        add_action('admin_post_sub_document_type_delete', [self::class, 'handleTypeDelete']);
        add_action('admin_post_sub_storage_save', [self::class, 'handleStorageSave']);
    }

    /**
     * La file, sous « Membres ».
     */
    public static function renderTab(): void
    {
        AdminUi::requireCap('sub_validate_member_document');

        self::renderStorageWarning();
        self::renderReviewQueue();
    }

    /**
     * Les types, sous « Réglages ».
     */
    public static function renderTypesTab(): void
    {
        AdminUi::requireCap('sub_manage_memberships');

        self::renderTypes();
    }

    /**
     * Le stockage, sous « Réglages ».
     */
    public static function renderStorageTab(): void
    {
        AdminUi::requireCap('sub_manage_memberships');

        self::renderStorageWarning();
        self::renderStorage();
    }

    /**
     * Avertit si le stockage n'offre pas les garanties annoncées.
     *
     * Mieux vaut le dire que laisser croire à une protection qui n'existe pas.
     */
    private static function renderStorageWarning(): void
    {
        $problems = [];

        if (!DocumentStorage::isOutsideWebRoot()) {
            $problems[] = 'Les fichiers sont stockés dans le répertoire des médias, protégé par '
                . '<code>.htaccess</code>. Sur un serveur qui l’ignore, ils resteraient atteignables. '
                . 'Définissez <code>SUBALCATEL_PRIVATE_DIR</code> dans <code>wp-config.php</code>, '
                . 'hors de la racine web.';
        }

        if (!DocumentStorage::keyIsInConfig()) {
            $problems[] = 'La clé de chiffrement est enregistrée en base. Une base compromise '
                . 'livrerait donc de quoi déchiffrer les certificats médicaux. Définissez '
                . '<code>SUBALCATEL_DOC_KEY</code> dans <code>wp-config.php</code>.';
        }

        if ($problems === []) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p><strong>Stockage à durcir avant la mise en production</strong></p>
            <ul style="list-style:disc;margin-left:20px;">
                <?php foreach ($problems as $problem) : ?>
                    <li><?php echo wp_kses_post($problem); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    private static function renderReviewQueue(): void
    {
        $service  = new DocumentService();
        $pending  = $service->pendingReview();
        ?>
        <p class="description">
            Un document déposé n’empêche pas le membre de s’inscrire : il a fait sa part.
            Une fois refusé, en revanche, il redevient manquant.
        </p>

        <table class="wp-list-table widefat striped sub-cards" style="margin-top:12px;">
            <thead>
                <tr>
                    <th>Membre</th>
                    <th style="width:180px;">Document</th>
                    <th style="width:150px;">Déposé le</th>
                    <th style="width:150px;">Validité</th>
                    <th style="width:340px;">Décision</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($pending === []) : ?>
                <tr><td colspan="5">Aucun document en attente. </td></tr>
            <?php endif; ?>

            <?php foreach ($pending as $row) : ?>
                <tr>
                    <td data-label="Membre">
                        <strong><?php echo esc_html((string) ($row['display_name'] ?: '—')); ?></strong>
                    </td>
                    <td data-label="Document">
                        <?php echo esc_html((string) $row['type_label']); ?><br>
                        <a href="<?php echo esc_url(DocumentsForm::downloadUrl((int) $row['id'])); ?>">
                            Ouvrir le fichier
                        </a>
                    </td>
                    <td data-label="Déposé le">
                        <?php echo esc_html(AdminUi::frDate(substr((string) $row['uploaded_at'], 0, 10))); ?>
                    </td>
                    <td data-label="Validité">
                        <?php echo esc_html(AdminUi::frDate((string) $row['valid_until'])); ?>
                    </td>
                    <td data-label="Décision">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <input type="hidden" name="action" value="sub_document_review">
                            <input type="hidden" name="document_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                            <?php wp_nonce_field('sub_document_review_' . $row['id']); ?>
                            <input type="text" name="reason" placeholder="Motif si refus" style="width:150px;">
                            <button class="button button-primary" name="decision" value="accept">Valider</button>
                            <button class="button" name="decision" value="reject">Refuser</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderTypes(): void
    {
        ?>
        <p class="description">
            Chaque type porte ses propres règles. Le certificat médical est le seul
            chiffré et journalisé : c’est une donnée de santé.
        </p>

        <?php foreach (DocumentTypes::all(false) as $type) : ?>
            <details class="sub-card">
                <summary>
                    <strong><?php echo esc_html((string) $type['label']); ?></strong>
                    <code><?php echo esc_html((string) $type['slug']); ?></code>
                    <?php if ((int) $type['encrypted'] === 1) : ?>
                        <span class="sub-tag">chiffré</span>
                    <?php endif; ?>
                    <?php if ((int) $type['blocks_dives'] === 1) : ?>
                        <span class="sub-tag">bloque les plongées</span>
                    <?php endif; ?>
                    <?php if ($type['required_when'] === DocumentTypes::REQUIRED_MINOR) : ?>
                        <span class="sub-tag">mineurs seulement</span>
                    <?php endif; ?>
                </summary>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
                    <input type="hidden" name="action" value="sub_document_type_save">
                    <input type="hidden" name="type_id" value="<?php echo esc_attr((string) $type['id']); ?>">
                    <?php wp_nonce_field('sub_document_type_save'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Libellé</th>
                            <td>
                                <input type="text" name="label" class="regular-text" required
                                       value="<?php echo esc_attr((string) $type['label']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Aide affichée au membre</th>
                            <td><textarea name="help" rows="2" class="large-text"><?php echo esc_textarea((string) $type['help']); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row">Exigé</th>
                            <td>
                                <select name="required_when">
                                    <option value="<?php echo esc_attr(DocumentTypes::REQUIRED_ALWAYS); ?>"
                                            <?php selected($type['required_when'], DocumentTypes::REQUIRED_ALWAYS); ?>>
                                        De tous les membres
                                    </option>
                                    <option value="<?php echo esc_attr(DocumentTypes::REQUIRED_MINOR); ?>"
                                            <?php selected($type['required_when'], DocumentTypes::REQUIRED_MINOR); ?>>
                                        Des mineurs seulement
                                    </option>
                                    <option value="<?php echo esc_attr(DocumentTypes::REQUIRED_NEVER); ?>"
                                            <?php selected($type['required_when'], DocumentTypes::REQUIRED_NEVER); ?>>
                                        Facultatif
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Validité</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="has_validity" value="1"
                                           <?php checked((int) $type['has_validity'], 1); ?>>
                                    Ce document a une date de fin de validité
                                </label><br>
                                <input type="number" name="validity_months" class="small-text" min="0"
                                       value="<?php echo esc_attr((string) $type['validity_months']); ?>"> mois,
                                puis suppression du fichier après
                                <input type="number" name="purge_delay_days" class="small-text" min="0"
                                       value="<?php echo esc_attr((string) $type['purge_delay_days']); ?>"> jours
                                <p class="description">
                                    Le délai de suppression laisse au membre le temps de récupérer son
                                    document. La trace de vérification, elle, est conservée.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Conséquences</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="blocks_dives" value="1"
                                           <?php checked((int) $type['blocks_dives'], 1); ?>>
                                    Un document manquant ou expiré empêche l’inscription aux plongées
                                </label><br>
                                <label>
                                    <input type="checkbox" name="needs_validation" value="1"
                                           <?php checked((int) $type['needs_validation'], 1); ?>>
                                    Doit être validé par le bureau
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Confidentialité</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="encrypted" value="1"
                                           <?php checked((int) $type['encrypted'], 1); ?>>
                                    Chiffrer le fichier sur le disque
                                </label><br>
                                <label>
                                    <input type="checkbox" name="log_access" value="1"
                                           <?php checked((int) $type['log_access'], 1); ?>>
                                    Journaliser chaque consultation
                                </label>
                                <p class="description">
                                    À conserver pour toute donnée de santé. Modifier le chiffrement
                                    n’affecte que les documents déposés ensuite.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Rappels</th>
                            <td>
                                <input type="text" name="reminder_days" class="small-text"
                                       value="<?php echo esc_attr((string) $type['reminder_days']); ?>">
                                <p class="description">Jours avant expiration, séparés par des virgules.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit"><button class="button button-primary">Enregistrer</button></p>
                </form>

                <?php
                // Frère du formulaire d'édition, jamais enfant.
                AdminUi::actionButton(
                    'sub_document_type_delete',
                    ['type_id' => (int) $type['id']],
                    'Supprimer ce type',
                    'button-link-delete button-link',
                    'Supprimer ce type de document ?'
                );
                ?>
            </details>
        <?php endforeach; ?>

        <h2 style="margin-top:28px;">Ajouter un type de document</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
            <input type="hidden" name="action" value="sub_document_type_create">
            <?php wp_nonce_field('sub_document_type_create'); ?>
            <p>
                <input type="text" name="label" class="regular-text" required
                       placeholder="Autorisation parentale, licence, assurance…">
                <button class="button button-primary">Ajouter</button>
            </p>
            <p class="description">
                Le type est créé facultatif et sans effet. Ouvrez-le ensuite pour régler
                sa validité, ses conséquences et sa confidentialité.
            </p>
        </form>
        <?php
    }

    /**
     * Configuration du lieu de stockage.
     */
    private static function renderStorage(): void
    {
        AdminUi::requireCap('sub_manage_memberships');

        $settings = DocumentStorage::settings();
        $current  = (string) $settings['driver'];
        ?>
        <p class="description">
            Où sont déposés les fichiers. Le site ne produit jamais d’adresse publique :
            quel que soit le support, un document passe par le serveur, qui vérifie les
            droits avant de l’envoyer.
        </p>

        <?php if (DocumentStorage::isRemote()) : ?>
            <div class="notice notice-info" style="margin:16px 0;">
                <p>
                    <strong>Stockage externe : deux obligations.</strong>
                    Le prestataire devient un <strong>sous-traitant</strong> au sens du RGPD —
                    il faut un contrat et l’inscrire au registre des traitements. Et les
                    certificats médicaux ne doivent quitter le serveur que <strong>chiffrés</strong> :
                    vérifiez que l’option reste active sur ce type.
                </p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['sub_test'])) : ?>
            <?php $result = DocumentStorage::test(); ?>
            <div class="notice notice-<?php echo $result['ok'] ? 'success' : 'error'; ?>">
                <p><strong><?php echo $result['ok'] ? 'Stockage opérationnel' : 'Stockage inutilisable'; ?></strong> —
                   <?php echo esc_html($result['message']); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sub-form">
            <input type="hidden" name="action" value="sub_storage_save">
            <?php wp_nonce_field('sub_storage_save'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Emplacement</th>
                    <td>
                        <?php foreach (DocumentStorage::drivers() as $key => $label) : ?>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="radio" name="driver" value="<?php echo esc_attr($key); ?>"
                                       <?php checked($current, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            Le disque du serveur reste recommandé : aucun sous-traitant, aucune clé
                            à gérer, aucune panne réseau entre le site et ses documents.
                        </p>
                    </td>
                </tr>
            </table>

            <h3>Disque du serveur</h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Répertoire</th>
                    <td>
                        <input type="text" name="path" class="large-text"
                               value="<?php echo esc_attr((string) $settings['path']); ?>"
                               placeholder="/home/club/private/documents">
                        <p class="description">
                            Laissez vide pour le dossier par défaut. La constante
                            <code>SUBALCATEL_PRIVATE_DIR</code> de <code>wp-config.php</code>, si elle
                            existe, l’emporte sur ce réglage.
                        </p>
                    </td>
                </tr>
            </table>

            <h3>Stockage objet compatible S3</h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Point de terminaison</th>
                    <td>
                        <input type="url" name="endpoint" class="regular-text"
                               value="<?php echo esc_attr((string) $settings['endpoint']); ?>"
                               placeholder="https://s3.fr-par.scw.cloud">
                        <p class="description">Scaleway, OVH, Infomaniak, MinIO… Préférez un hébergeur européen.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Région</th>
                    <td><input type="text" name="region" class="small-text"
                               value="<?php echo esc_attr((string) $settings['region']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Bucket</th>
                    <td>
                        <input type="text" name="bucket" class="regular-text"
                               value="<?php echo esc_attr((string) $settings['bucket']); ?>">
                        <p class="description"><strong>Il doit être privé.</strong> Le plugin ne rend jamais un objet public.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Clé d’accès</th>
                    <td><input type="text" name="access_key" class="regular-text"
                               value="<?php echo esc_attr((string) $settings['access_key']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Clé secrète</th>
                    <td>
                        <input type="password" name="secret_key" class="regular-text" autocomplete="new-password"
                               placeholder="<?php echo $settings['secret_key'] !== '' ? '•••••••• (inchangée)' : ''; ?>">
                        <p class="description">Laissez vide pour conserver la clé enregistrée.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Préfixe</th>
                    <td><input type="text" name="prefix" class="regular-text"
                               value="<?php echo esc_attr((string) $settings['prefix']); ?>"></td>
                </tr>
            </table>

            <h3>Partage WebDAV</h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Adresse</th>
                    <td>
                        <input type="url" name="url" class="large-text"
                               value="<?php echo esc_attr((string) $settings['url']); ?>"
                               placeholder="https://nuage.example.org/remote.php/dav/files/club/documents">
                        <p class="description">
                            Nextcloud, kDrive, Synology… Créez un <strong>compte dédié</strong> et un
                            <strong>dossier réservé</strong> : pas le partage commun du bureau.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Identifiant</th>
                    <td><input type="text" name="user" class="regular-text"
                               value="<?php echo esc_attr((string) $settings['user']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Mot de passe</th>
                    <td>
                        <input type="password" name="password" class="regular-text" autocomplete="new-password"
                               placeholder="<?php echo $settings['password'] !== '' ? '•••••••• (inchangé)' : ''; ?>">
                        <p class="description">
                            Utilisez un mot de passe d’application, pas celui du compte principal.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button class="button button-primary" name="and_test" value="0">Enregistrer</button>
                <button class="button" name="and_test" value="1">Enregistrer et tester</button>
            </p>
        </form>

        <p class="description">
            <strong>Emplacement actuel :</strong> <code><?php echo esc_html(DocumentStorage::describe()); ?></code>
        </p>

        <div class="notice notice-warning inline" style="margin-top:16px;">
            <p>
                <strong>Changer d’emplacement ne déplace pas les fichiers déjà déposés.</strong>
                Ils resteront là où ils sont et deviendront illisibles. Prévoyez une
                reprise avant de basculer un stockage qui contient déjà des documents.
            </p>
        </div>
        <?php
    }

    /**
     * Retour sur la file, sous « Membres ».
     */
    private static function backToQueue(string $message, bool $isError = false): never
    {
        AdminUi::redirect(MembersScreen::SLUG, $message, $isError, ['tab' => self::TAB]);
    }

    /**
     * Retour sur l'onglet de configuration d'où venait le formulaire.
     *
     * @param array<string, string|int> $extraArgs
     */
    private static function backToSettings(
        string $tab,
        string $message,
        bool $isError = false,
        array $extraArgs = [],
    ): never {
        AdminUi::redirect(SettingsScreen::SLUG, $message, $isError, ['tab' => $tab] + $extraArgs);
    }

    public static function handleReview(): void
    {
        $documentId = absint($_POST['document_id'] ?? 0);
        check_admin_referer('sub_document_review_' . $documentId);

        $accepted = ($_POST['decision'] ?? '') === 'accept';
        $reason   = sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? '')));

        if (!$accepted && $reason === '') {
            self::backToQueue('Un refus doit être motivé : le membre doit savoir quoi corriger.', true);
        }

        try {
            (new DocumentService())->review($documentId, $accepted, get_current_user_id(), $reason);
            self::backToQueue($accepted ? 'Document validé.' : 'Document refusé.');
        } catch (\RuntimeException $e) {
            self::backToQueue($e->getMessage(), true);
        }
    }

    public static function handleTypeCreate(): void
    {
        check_admin_referer('sub_document_type_create');
        AdminUi::requireCap('sub_manage_memberships');

        $label = sanitize_text_field(wp_unslash((string) ($_POST['label'] ?? '')));

        if ($label === '') {
            self::backToSettings(self::TAB_TYPES, 'Le libellé est obligatoire.', true);
        }

        $id = DocumentTypes::create(['label' => $label]);
        \Subalcatel\Club\Support\Audit::log('document_type.created', 'document_type', $id, ['label' => $label]);

        self::backToSettings(self::TAB_TYPES, 'Type créé. Ouvrez-le pour régler sa validité et ses conséquences.');
    }

    public static function handleTypeDelete(): void
    {
        check_admin_referer('sub_document_type_delete');
        AdminUi::requireCap('sub_manage_memberships');

        $typeId  = absint($_POST['type_id'] ?? 0);
        $message = DocumentTypes::remove($typeId);

        \Subalcatel\Club\Support\Audit::log('document_type.deleted', 'document_type', $typeId);

        self::backToSettings(self::TAB_TYPES, $message !== '' ? $message : 'Type supprimé.');
    }

    public static function handleStorageSave(): void
    {
        check_admin_referer('sub_storage_save');
        AdminUi::requireCap('sub_manage_memberships');

        $current = DocumentStorage::settings();

        $settings = [
            'driver'     => sanitize_key(wp_unslash((string) ($_POST['driver'] ?? 'local'))),
            'path'       => sanitize_text_field(wp_unslash((string) ($_POST['path'] ?? ''))),
            'endpoint'   => esc_url_raw(wp_unslash((string) ($_POST['endpoint'] ?? ''))),
            'region'     => sanitize_text_field(wp_unslash((string) ($_POST['region'] ?? 'fr-par'))),
            'bucket'     => sanitize_text_field(wp_unslash((string) ($_POST['bucket'] ?? ''))),
            'access_key' => sanitize_text_field(wp_unslash((string) ($_POST['access_key'] ?? ''))),
            'prefix'     => sanitize_text_field(wp_unslash((string) ($_POST['prefix'] ?? 'documents'))),
            'path_style' => true,
            'url'        => esc_url_raw(wp_unslash((string) ($_POST['url'] ?? ''))),
            'user'       => sanitize_text_field(wp_unslash((string) ($_POST['user'] ?? ''))),
        ];

        // Un champ de mot de passe laissé vide conserve la valeur enregistrée :
        // sinon, rouvrir l'écran pour corriger une région effacerait la clé.
        foreach (['secret_key' => 'secret_key', 'password' => 'password'] as $field) {
            $posted = (string) ($_POST[$field] ?? '');
            $settings[$field] = $posted !== '' ? $posted : (string) $current[$field];
        }

        DocumentStorage::saveSettings($settings);

        // Les secrets ne sont jamais journalisés.
        \Subalcatel\Club\Support\Audit::log('storage.configured', 'system', null, [
            'driver' => $settings['driver'],
        ]);

        $args = ($_POST['and_test'] ?? '0') === '1' ? ['sub_test' => '1'] : [];

        self::backToSettings(self::TAB_STORAGE, 'Configuration du stockage enregistrée.', false, $args);
    }

    public static function handleTypeSave(): void
    {
        check_admin_referer('sub_document_type_save');
        AdminUi::requireCap('sub_manage_memberships');

        global $wpdb;

        $typeId = absint($_POST['type_id'] ?? 0);
        $label  = sanitize_text_field(wp_unslash((string) ($_POST['label'] ?? '')));

        if ($label === '') {
            self::backToSettings(self::TAB_TYPES, 'Le libellé est obligatoire.', true);
        }

        $wpdb->update("{$wpdb->prefix}sub_document_types", [
            'label'            => $label,
            'help'             => sanitize_textarea_field(wp_unslash((string) ($_POST['help'] ?? ''))),
            'required_when'    => sanitize_key(wp_unslash((string) ($_POST['required_when'] ?? 'always'))),
            'is_required'      => ($_POST['required_when'] ?? '') === DocumentTypes::REQUIRED_NEVER ? 0 : 1,
            'has_validity'     => isset($_POST['has_validity']) ? 1 : 0,
            'validity_months'  => absint($_POST['validity_months'] ?? 12),
            'purge_delay_days' => absint($_POST['purge_delay_days'] ?? 30),
            'blocks_dives'     => isset($_POST['blocks_dives']) ? 1 : 0,
            'needs_validation' => isset($_POST['needs_validation']) ? 1 : 0,
            'encrypted'        => isset($_POST['encrypted']) ? 1 : 0,
            'log_access'       => isset($_POST['log_access']) ? 1 : 0,
            'reminder_days'    => sanitize_text_field(wp_unslash((string) ($_POST['reminder_days'] ?? ''))),
        ], ['id' => $typeId]);

        \Subalcatel\Club\Support\Audit::log('document_type.saved', 'document_type', $typeId, ['label' => $label]);

        self::backToSettings(self::TAB_TYPES, 'Type enregistré.');
    }
}
