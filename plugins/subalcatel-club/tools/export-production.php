<?php
/**
 * Prépare l'export propre pour la mise en production.
 *
 * Ne s'exécute JAMAIS sur la base de développement : ce script refuse de
 * tourner ailleurs que sur une base nommée "sub_export", clonée au préalable
 * depuis la base de travail. Voir README-export.md pour la marche à suivre
 * complète (clonage, exécution, search-replace, export final).
 *
 * Ce qui est gardé :
 *   - les utilisateurs marqués par la reprise Joomla (UserImporter)
 *   - les articles marqués par la reprise Joomla (ArticleImporter), et leurs
 *     médias attachés
 *   - la campagne d'adhésion en cours (campagne-2026-2027) et toute campagne
 *     de reprise (statut "closed"), avec leurs plans/options/remises
 *   - les adhésions (applications) et leurs lignes/paiements/validations,
 *     pour les seuls utilisateurs et campagnes gardés
 *   - les pages du site (post_type=page) : jamais supprimées automatiquement,
 *     seulement listées pour relecture — impossible de distinguer d'ici une
 *     page réelle du site d'une page de test.
 *
 * Ce qui est déclassé (le compte reste, ses privilèges partent) :
 *   - les droits bureau portés par un compte personnel : ils ne vivent que sur
 *     les comptes d'administration « admin_* » (voir §2)
 *
 * Ce qui est supprimé :
 *   - tout compte WordPress sans marque de reprise (comptes de test créés
 *     pendant le développement, compte admin par défaut, etc.)
 *   - tout article (post_type=post) sans marque de reprise (ex. "Hello World")
 *   - la campagne "campagne-de-test" et toute campagne en brouillon
 *   - les pièces jointes orphelines (aucun article/page ne les référence)
 *   - les lignes des tables sub_* rattachées aux comptes/campagnes supprimés
 *     (adhésions, paiements, inscriptions aux sorties, historique de niveau,
 *     groupes de diffusion, documents membres)
 *   - les options WordPress orphelines d'un dossier d'adhésion supprimé
 *     (réponses au formulaire, correspondance avec l'identifiant Joomla)
 *
 * Ce qui n'est jamais touché : le journal d'audit (sub_audit_log, c'est une
 * trace de sécurité, pas une donnée métier), les pages, la configuration du
 * club (types d'événements, groupes de diffusion, modèles d'e-mail, types de
 * documents).
 *
 *   docker exec -e WORDPRESS_DB_NAME=sub_export sub_demo_cli \
 *     wp --allow-root eval-file wp-content/plugins/subalcatel-club/tools/export-production.php
 *        → simulation, aucune écriture
 *   ... export-production.php write
 *        → suppression réelle, sur sub_export uniquement
 */

use Subalcatel\Club\Import\UserImporter;
use Subalcatel\Club\Import\ArticleImporter;
use Subalcatel\Club\Import\MembershipImporter;
use Subalcatel\Club\Identity\DerivedCapabilities;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Policy\EligibilityPolicy;

global $wpdb;

$argsList = $args ?? [];
$dryRun   = !in_array('write', $argsList, true);

// --- Garde-fou : jamais sur la base de développement ------------------------
if ($wpdb->dbname !== 'sub_export') {
    fwrite(STDERR, sprintf(
        "\nRefus : base courante « %s », attendu « sub_export ».\n" .
        "Ce script ne doit tourner que sur le clone dédié à l'export, jamais\n" .
        "sur la base de développement. Voir README-export.md.\n\n",
        $wpdb->dbname
    ));
    exit(1);
}

printf("\n%s\n", $dryRun
    ? '=== SIMULATION (aucune écriture) — relancer avec « write » pour appliquer ==='
    : '=== ÉCRITURE RÉELLE sur sub_export ==='
);

$sp = $wpdb->prefix . 'sub_';

// =============================================================================
// 1. Utilisateurs — on garde ceux marqués par la reprise Joomla
// =============================================================================

$keepUserIds = array_map('intval', $wpdb->get_col(
    "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = '" . UserImporter::JOOMLA_ID_META . "'"
));
$allUserIds  = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users}"));
$dropUserIds = array_values(array_diff($allUserIds, $keepUserIds));

printf(
    "\nUTILISATEURS    %4d à garder (repris Joomla)   %4d à supprimer (test/démo/admin par défaut)\n",
    count($keepUserIds),
    count($dropUserIds)
);

foreach ($dropUserIds as $uid) {
    $user = get_userdata($uid);
    printf("    - #%-5d %-30s <%s>\n", $uid, $user->display_name ?? '?', $user->user_email ?? '?');
}

if (!$dryRun) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($dropUserIds as $uid) {
        wp_delete_user($uid);
    }
}

// =============================================================================
// 2. Droits bureau — réservés aux comptes d'administration « admin_* »
//
// Plusieurs membres du bureau arrivent de Joomla avec deux comptes : un compte
// personnel, qui porte l'adhésion, le niveau de plongée et l'historique, et un
// compte d'administration « admin_<nom> », qui n'en porte aucun. Décision du
// bureau : les droits d'administration ne vivent que sur le second. Un compte
// personnel qui garderait `sub_office` doublerait le privilège sans le dire —
// et c'est le compte du quotidien, celui qui reste connecté.
//
// Le compte déclassé redevient ce que son adhésion dit qu'il est : `sub_member`
// s'il est à jour, `sub_guest` sinon. Rien n'est supprimé.
//
// La capacité « créer une sortie » part avec le reste, et ce n'est pas une
// perte : elle est déduite du niveau de plongée à chaque requête
// ([DerivedCapabilities]) — un E3 à jour la retrouve immédiatement, sans
// intervention. Les exemplaires écrits en dur par la reprise Joomla masquaient
// cette règle au lieu de la servir : un compte les gardait après un changement
// de niveau ou une adhésion expirée. Le rapport signale nommément tout compte
// pour qui le niveau ne redonne pas ce que la capacité stockée lui donnait.
// =============================================================================

$eligibility   = new EligibilityPolicy();
$grantableCaps = array_keys(Roles::CAPABILITIES);

$officeKeptIds    = [];
$officeDemotedIds = [];

foreach ($keepUserIds as $uid) {
    $user = get_userdata($uid);
    if (!$user instanceof WP_User || !in_array(Roles::OFFICE, $user->roles, true)) {
        continue;
    }
    // « admin » n'importe où dans l'identifiant : la reprise a produit
    // `admin_pivette` mais rien ne garantit ce préfixe pour un compte créé
    // ensuite à la main.
    if (stripos($user->user_login, 'admin') !== false) {
        $officeKeptIds[] = $uid;
    } else {
        $officeDemotedIds[] = $uid;
    }
}

printf(
    "\nDROITS BUREAU   %4d conservés (comptes « admin_* »)   %4d retirés (comptes personnels)\n",
    count($officeKeptIds),
    count($officeDemotedIds)
);

foreach ($officeKeptIds as $uid) {
    $user = get_userdata($uid);
    printf("    - [garde ] #%-5d %-18s %s\n", $uid, $user->user_login, $user->display_name);
}

foreach ($officeDemotedIds as $uid) {
    $user    = get_userdata($uid);
    $target  = $eligibility->hasActiveMembership($uid)->allowed ? Roles::MEMBER : Roles::GUEST;
    $stored  = array_values(array_intersect($grantableCaps, array_keys(array_filter($user->caps))));
    $derived = array_keys(DerivedCapabilities::forUser($uid));
    $lost    = array_values(array_diff($stored, $derived));

    printf("    - [RETIRE] #%-5d %-18s %-24s %s → %s\n",
        $uid, $user->user_login, $user->display_name, Roles::OFFICE, $target);

    foreach ($stored as $cap) {
        printf("               capacité retirée : %-32s %s\n", $cap,
            in_array($cap, $derived, true)
                ? 'rendue par le niveau de plongée'
                : 'NON rendue par le niveau — à vérifier');
    }

    if ($lost !== []) {
        printf("               ATTENTION : ce compte perd réellement %s\n", implode(', ', $lost));
    }
}

if (!$dryRun) {
    foreach ($officeDemotedIds as $uid) {
        $user = get_userdata($uid);
        $user->set_role($eligibility->hasActiveMembership($uid)->allowed ? Roles::MEMBER : Roles::GUEST);

        // `set_role()` ne remplace que le rôle : les capacités accordées nommément
        // survivent dans la même méta et continueraient d'ouvrir les écrans du
        // bureau. Il faut les retirer une à une.
        foreach ($grantableCaps as $cap) {
            if (isset($user->caps[$cap])) {
                $user->remove_cap($cap);
            }
        }
    }
}

// =============================================================================
// 3. Articles — on garde ceux marqués par la reprise Joomla (post_type=post)
//    Les pages ne sont jamais supprimées automatiquement : listées pour revue.
// =============================================================================

$keepPostIds = array_map('intval', $wpdb->get_col(
    "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . ArticleImporter::JOOMLA_ID_META . "'"
));
$allPostIds  = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post'"
));
$dropPostIds = array_values(array_diff($allPostIds, $keepPostIds));

printf(
    "\nARTICLES         %4d à garder (repris Joomla)   %4d à supprimer (contenu de test/par défaut)\n",
    count($keepPostIds),
    count($dropPostIds)
);

foreach ($dropPostIds as $pid) {
    $post = get_post($pid);
    printf("    - #%-5d %s\n", $pid, $post->post_title !== '' ? $post->post_title : '(sans titre)');
}

if (!$dryRun) {
    foreach ($dropPostIds as $pid) {
        wp_delete_post($pid, true);
    }
}

$pageIds = $wpdb->get_results(
    "SELECT ID, post_title, post_status FROM {$wpdb->posts} WHERE post_type = 'page' ORDER BY post_title",
    ARRAY_A
);
printf("\nPAGES            %4d trouvées — non touchées, à relire à la main avant mise en ligne :\n", count($pageIds));
foreach ($pageIds as $page) {
    printf("    - #%-5s [%s] %s\n", $page['ID'], $page['post_status'], $page['post_title'] !== '' ? $page['post_title'] : '(sans titre)');
}

// =============================================================================
// 4. Pièces jointes orphelines — aucun post/page ne les référence
// =============================================================================

$referencedThumbs = array_map('intval', $wpdb->get_col(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'"
));
$attachedTo = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent != 0"
));
$keepMediaIds  = array_unique(array_merge($referencedThumbs, $attachedTo));
$allMediaIds   = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'"
));
$dropMediaIds  = array_values(array_diff($allMediaIds, $keepMediaIds));

printf(
    "\nMÉDIAS           %4d gardés (référencés)        %4d orphelins à supprimer\n",
    count($keepMediaIds),
    count($dropMediaIds)
);

if (!$dryRun) {
    foreach ($dropMediaIds as $mid) {
        wp_delete_attachment($mid, true);
    }
}

// =============================================================================
// 5. Campagnes d'adhésion — on garde la campagne en cours + les reprises
//    (statut "closed"). On supprime tout brouillon, dont "campagne-de-test".
// =============================================================================

$allCampaigns = $wpdb->get_results("SELECT id, slug, status FROM {$sp}campaigns", ARRAY_A);
$keepCampaignIds = [];
$dropCampaignIds = [];
foreach ($allCampaigns as $c) {
    $keep = in_array($c['status'], ['open', 'closed'], true) && $c['slug'] !== 'campagne-de-test';
    if ($keep) {
        $keepCampaignIds[] = (int) $c['id'];
    } else {
        $dropCampaignIds[] = (int) $c['id'];
    }
}

printf("\nCAMPAGNES        %4d gardées   %4d supprimées (brouillons, campagne de test)\n",
    count($keepCampaignIds), count($dropCampaignIds));
foreach ($allCampaigns as $c) {
    $mark = in_array((int) $c['id'], $keepCampaignIds, true) ? 'garde ' : 'RETIRE';
    printf("    - [%s] #%-4d %-28s (%s)\n", $mark, $c['id'], $c['slug'], $c['status']);
}

if (!$dryRun && $dropCampaignIds !== []) {
    $ph = implode(',', array_fill(0, count($dropCampaignIds), '%d'));
    foreach (['plans', 'options', 'discount_rules'] as $table) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$sp}{$table} WHERE campaign_id IN ($ph)", ...$dropCampaignIds));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$sp}campaigns WHERE id IN ($ph)", ...$dropCampaignIds));
}

// =============================================================================
// 6. Adhésions (applications) — gardées seulement si utilisateur ET campagne
//    gardés. Cascade sur les lignes, validations, paiements.
// =============================================================================

$allApplications = $wpdb->get_results("SELECT id, user_id, campaign_id FROM {$sp}applications", ARRAY_A);
$dropApplicationIds = [];
foreach ($allApplications as $a) {
    $userOk     = in_array((int) $a['user_id'], $keepUserIds, true);
    $campaignOk = in_array((int) $a['campaign_id'], $keepCampaignIds, true);
    if (!$userOk || !$campaignOk) {
        $dropApplicationIds[] = (int) $a['id'];
    }
}

printf("\nADHÉSIONS        %4d gardées   %4d supprimées (compte ou campagne non gardés)\n",
    count($allApplications) - count($dropApplicationIds), count($dropApplicationIds));

if (!$dryRun && $dropApplicationIds !== []) {
    $ph = implode(',', array_fill(0, count($dropApplicationIds), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$sp}application_lines WHERE application_id IN ($ph)", ...$dropApplicationIds));
    $wpdb->query($wpdb->prepare("DELETE FROM {$sp}validations WHERE application_id IN ($ph)", ...$dropApplicationIds));
    $wpdb->query($wpdb->prepare("DELETE FROM {$sp}payments WHERE application_id IN ($ph)", ...$dropApplicationIds));
    $wpdb->query($wpdb->prepare("DELETE FROM {$sp}applications WHERE id IN ($ph)", ...$dropApplicationIds));

    // Options rattachées à ces dossiers (réponses au formulaire d'adhésion,
    // correspondance identifiant Joomla → dossier) : orphelines dès que le
    // dossier disparaît, jamais nettoyées par les DELETE ci-dessus puisque
    // wp_options n'a pas de clé étrangère vers sub_applications.
    foreach ($dropApplicationIds as $appId) {
        delete_option("sub_application_answers_{$appId}");
    }
    $legacyPh = implode(',', array_fill(0, count($dropApplicationIds), '%s'));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value IN ($legacyPh)",
        $wpdb->esc_like(MembershipImporter::JOOMLA_ID_META) . '_%',
        ...array_map('strval', $dropApplicationIds)
    ));
}

// =============================================================================
// 7. Reliquats liés aux comptes supprimés, dans les autres tables sub_*
// =============================================================================

$userLinkedTables = [
    'dive_level_history'   => 'user_id',
    'event_registrations'  => 'user_id',
    'mailing_group_members'=> 'user_id',
    'payments'              => 'user_id',
];

printf("\nAUTRES TABLES    reliquats liés aux comptes retirés :\n");
foreach ($userLinkedTables as $table => $column) {
    if ($dropUserIds === []) {
        continue;
    }
    $ph    = implode(',', array_fill(0, count($dropUserIds), '%d'));
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$sp}{$table} WHERE {$column} IN ($ph)", ...$dropUserIds
    ));
    printf("    - %-24s %4d ligne(s)\n", $table, $count);
    if (!$dryRun && $count > 0) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$sp}{$table} WHERE {$column} IN ($ph)", ...$dropUserIds));
    }
}

// member_documents + son journal de consultation, en cascade.
if ($dropUserIds !== []) {
    $ph = implode(',', array_fill(0, count($dropUserIds), '%d'));
    $dropDocIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$sp}member_documents WHERE user_id IN ($ph)", ...$dropUserIds
    )));
    printf("    - %-24s %4d ligne(s)\n", 'member_documents', count($dropDocIds));
    if (!$dryRun && $dropDocIds !== []) {
        $docPh = implode(',', array_fill(0, count($dropDocIds), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$sp}document_access_log WHERE document_id IN ($docPh)", ...$dropDocIds));
        $wpdb->query($wpdb->prepare("DELETE FROM {$sp}member_documents WHERE id IN ($docPh)", ...$dropDocIds));
    }
}

printf("\n--- sub_audit_log n'est jamais modifié par ce script : trace de sécurité, pas une donnée métier. ---\n");

printf("\n%s\n\n", $dryRun
    ? 'Fin de simulation. Relisez la liste ci-dessus, puis relancez avec « write » pour supprimer réellement.'
    : 'Nettoyage terminé sur sub_export. Étape suivante : search-replace de l’URL, puis wp db export.'
);
