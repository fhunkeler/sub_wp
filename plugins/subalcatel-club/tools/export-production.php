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
 *   - les quatre comptes « admin_* » eux-mêmes, marqués comptes techniques et
 *     vidés de leurs données de membre : hors annuaire, hors listes de
 *     diffusion sauf « Bureau » (voir §3)
 *   - les capacités « créer une sortie » écrites en dur par la reprise Joomla,
 *     retirées partout au profit du niveau de plongée (voir §4)
 *
 * Ce qui est supprimé :
 *   - tout compte WordPress sans marque de reprise (comptes de test créés
 *     pendant le développement, compte admin par défaut, etc.)
 *   - tout article (post_type=post) sans marque de reprise (ex. "Hello World")
 *   - la campagne "campagne-de-test" et toute campagne en brouillon
 *   - les pièces jointes orphelines (aucun article/page ne les référence) :
 *     retirées de la base seulement, leurs fichiers restent sur disque
 *   - les lignes des tables sub_* rattachées aux comptes/campagnes supprimés
 *     (adhésions, paiements, inscriptions aux sorties, historique de niveau,
 *     groupes de diffusion, documents membres)
 *   - les options WordPress orphelines d'un dossier d'adhésion supprimé
 *     (réponses au formulaire, correspondance avec l'identifiant Joomla)
 *
 * Ce qui est réécrit : les adresses d'anciennes machines de développement
 *   (127.0.0.1, IP privées) dans le texte des contenus, ramenées à l'URL
 *   actuelle avant le search-replace (voir §10)
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
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\TechnicalAccounts;

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
// 3. Comptes techniques — les `admin_*` ne sont pas des adhérents
//
// Les quatre comptes d'administration n'ont ni adhésion, ni contenu, ni
// inscription à une sortie : ils ne désignent personne, ils ouvrent des
// écrans. [MembersScreen] avait déjà sorti les administrateurs WordPress de
// l'annuaire pour cette raison — « ils y apparaissaient éternellement
// *adhésion pas à jour*, ils n'ont pas d'adhésion à être à jour » — mais la
// règle raisonnait sur le rôle, et ces comptes-ci portent `sub_office`.
//
// On les marque donc explicitement, ce qui les sort de l'annuaire et de toutes
// les listes de diffusion sauf « Bureau », et on efface ce qu'ils portent de
// personnel : un compte technique qui garderait un niveau de plongée
// ressortirait dans « Encadrants », et le formulaire de profil réclamerait à
// son titulaire une date de naissance et une personne à prévenir en cas
// d'accident.
// =============================================================================

$technicalLogins = ['admin_langlais', 'admin_pivette', 'admin_rougeolle', 'admin_tuffin'];

$technicalIds = [];
foreach ($technicalLogins as $login) {
    $user = get_user_by('login', $login);

    if (!$user instanceof WP_User) {
        printf("\nCOMPTES TECHNIQUES   introuvable : %s (déjà supprimé ?) — ignoré\n", $login);
        continue;
    }

    $technicalIds[] = (int) $user->ID;
}

printf("\nCOMPTES TECHNIQUES %4d marqués — hors annuaire et hors listes de diffusion (sauf « Bureau »)\n",
    count($technicalIds));

foreach ($technicalIds as $uid) {
    $user  = get_userdata($uid);
    $level = DiveLevels::forUser($uid);

    printf("    - #%-5d %-18s %-28s niveau : %s\n",
        $uid, $user->user_login, $user->user_email, $level?->name ?? '(aucun)');

    if ($dryRun) {
        // En simulation, `mark()` n'est pas appelée : on montre ce qu'elle
        // effacerait, sans l'effacer. Même requête qu'elle, même résultat.
        $would = array_values(array_diff(
            $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
                  WHERE user_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s)",
                $uid,
                $wpdb->esc_like('sub_') . '%',
                $wpdb->esc_like('_sub_') . '%'
            )) ?: [],
            ['_sub_joomla_user_id', TechnicalAccounts::META]
        ));
        sort($would);
        printf("               données de membre à effacer : %s\n",
            $would === [] ? 'aucune' : implode(', ', $would));
        continue;
    }

    $cleared = TechnicalAccounts::mark($uid);
    printf("               données de membre effacées : %s\n",
        $cleared === [] ? 'aucune' : implode(', ', $cleared));
}

// =============================================================================
// 4. Capacités « créer une sortie » écrites en dur — retirées partout
//
// La reprise Joomla posait `sub_create_exploration_event` et
// `sub_create_training_event` nommément sur chaque directeur de plongée. C'est
// exactement ce que [DerivedCapabilities] calcule désormais à chaque requête,
// à partir du niveau de plongée **et** d'une adhésion à jour — et la capacité
// stockée l'emporte sur le calcul, puisqu'on ajoute sans jamais retirer.
//
// Résultat : le droit survivait à un changement de niveau et à une adhésion
// expirée, ce que la règle du niveau visait précisément à empêcher. On les
// retire donc toutes ; la règle s'applique alors à tout le monde de la même
// façon. Le rapport nomme les comptes pour qui le niveau ne redonne pas le
// droit — ce sont les cas où la capacité stockée masquait réellement quelque
// chose.
// =============================================================================

$dpCapabilities = ['sub_create_exploration_event', 'sub_create_training_event'];

// §3 vient de retirer leur niveau aux comptes techniques : le cache des
// capacités déduites, posé avant, dirait encore le contraire.
DerivedCapabilities::forget();

$storedDpIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
    "SELECT user_id FROM {$wpdb->usermeta}
      WHERE meta_key = %s AND meta_value LIKE %s",
    $wpdb->prefix . 'capabilities',
    '%' . $wpdb->esc_like('sub_create_') . '%'
)));

printf("\nCAPACITÉS DP     %4d compte(s) portent encore la capacité écrite en dur\n", count($storedDpIds));

foreach ($storedDpIds as $uid) {
    $user    = get_userdata($uid);
    $level   = DiveLevels::forUser($uid);
    $stored  = array_values(array_intersect($dpCapabilities, array_keys(array_filter($user->caps))));
    $derived = array_keys(DerivedCapabilities::forUser($uid));
    $lost    = array_values(array_diff($stored, $derived));

    printf("    - #%-5d %-18s niveau %-8s %s\n", $uid, $user->user_login, $level?->name ?? '(aucun)',
        $lost === []
            ? 'le niveau redonne le droit — retrait sans effet'
            : 'PERD ' . implode(', ', str_replace(['sub_create_', '_event'], '', $lost))
              . ' (adhésion ou niveau insuffisant)');

    if (!$dryRun) {
        foreach ($dpCapabilities as $cap) {
            if (isset($user->caps[$cap])) {
                $user->remove_cap($cap);
            }
        }
    }
}

// =============================================================================
// 5. Articles — on garde ceux marqués par la reprise Joomla (post_type=post)
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
// 6. Pièces jointes orphelines — aucun post/page ne les référence
//
// Un média est gardé s'il est l'image à la une d'un contenu, s'il est rattaché
// à un contenu (post_parent), ou s'il est cité dans le texte d'un contenu
// restant. Les médias repris de Joomla sont presque tous dans le dernier cas :
// une image insérée dans un article n'a ni parent ni rôle d'image à la une.
// Les ignorer, c'est les supprimer alors qu'ils s'affichent.
//
// On les reconnaît de deux façons : par l'identifiant que porte le balisage de
// l'éditeur (classe `wp-image-123`, attribut `"id":123` d'un bloc image), et
// par le chemin du fichier, pour le HTML repris tel quel. Le chemin est cherché
// sans extension ni suffixe `-scaled` : le texte cite une taille
// intermédiaire (`photo-1024x537.jpg`), jamais le fichier principal.
//
// Les médias écartés sont retirés de la base seulement. `wp_delete_attachment()`
// effacerait aussi leurs fichiers, or ce clone partage `wp-content/uploads/`
// avec le site de développement : chaque export supprimait des photos du dev.
// Le fichier d'un média écarté n'est de toute façon pas copié (voir
// list-kept-media.php).
// =============================================================================

$referencedThumbs = array_map('intval', $wpdb->get_col(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'"
));
$attachedTo = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent != 0"
));

// Après la suppression des articles de test (§5), en écriture : seul le texte
// des contenus restants compte. En simulation, ils sont encore là — le compte
// des médias gardés est alors un majorant.
$contents = implode("\n", $wpdb->get_col(
    "SELECT post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment', 'revision')"
));

$citedById = [];
if (preg_match_all('#\bwp-image-(\d+)\b|"id":(\d+)#', $contents, $m)) {
    foreach (array_merge($m[1], $m[2]) as $id) {
        if ($id !== '') {
            $citedById[] = (int) $id;
        }
    }
}

$citedByPath = [];
foreach ($wpdb->get_results(
    "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'",
    ARRAY_A
) as $row) {
    $stem = preg_replace('#(-scaled)?\.[a-z0-9]+$#i', '', $row['meta_value']);
    if ($stem !== '' && strpos($contents, 'uploads/' . $stem) !== false) {
        $citedByPath[] = (int) $row['post_id'];
    }
}

$keepMediaIds  = array_unique(array_merge($referencedThumbs, $attachedTo, $citedById, $citedByPath));
$allMediaIds   = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'"
));
$keepMediaIds  = array_values(array_intersect($allMediaIds, $keepMediaIds));
$dropMediaIds  = array_values(array_diff($allMediaIds, $keepMediaIds));

printf(
    "\nMÉDIAS           %4d gardés (référencés)        %4d orphelins à supprimer (base seulement, fichiers intacts)\n",
    count($keepMediaIds),
    count($dropMediaIds)
);

if (!$dryRun && $dropMediaIds !== []) {
    $mediaPh = implode(',', array_fill(0, count($dropMediaIds), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($mediaPh)", ...$dropMediaIds));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($mediaPh)", ...$dropMediaIds));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID IN ($mediaPh)", ...$dropMediaIds));
}

// =============================================================================
// 7. Campagnes d'adhésion — on garde la campagne en cours + les reprises
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
// 8. Adhésions (applications) — gardées seulement si utilisateur ET campagne
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
// 9. Reliquats liés aux comptes supprimés, dans les autres tables sub_*
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

// =============================================================================
// 10. Adresses d'anciennes machines de développement, dans le texte
//
// L'étape suivante de l'export remplace l'URL du site de développement par
// celle de la production — mais seulement l'URL qu'il porte AUJOURD'HUI. Le
// site a changé d'adresse en cours de route (127.0.0.1, 192.168.0.40,
// 10.22.4.143…), et un contenu enregistré sous une ancienne adresse la garde
// dans ses liens d'images. Le 12/10/2026, 141 images de 36 articles pointaient
// ainsi en production sur http://127.0.0.1 : le navigateur du visiteur les
// cherchait sur sa propre machine.
//
// On ramène donc toute adresse locale ou privée à l'URL actuelle ; le
// search-replace qui suit les emmène en production avec le reste. Seuls
// post_content et post_excerpt sont touchés : du texte, jamais sérialisé. Les
// guid gardent leur valeur d'origine, comme WordPress le demande.
// =============================================================================

$devHostPattern = '#https?://(?:127\.0\.0\.1|localhost|10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2})(?::\d+)?(?=[/"\'\s]|$)#';
$currentUrl     = untrailingslashit((string) get_option('siteurl'));

$devHostRows = $wpdb->get_results(
    "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
      WHERE post_content REGEXP 'https?://(127\\\\.0\\\\.0\\\\.1|localhost|10\\\\.|192\\\\.168\\\\.|172\\\\.)'
         OR post_excerpt REGEXP 'https?://(127\\\\.0\\\\.0\\\\.1|localhost|10\\\\.|192\\\\.168\\\\.|172\\\\.)'",
    ARRAY_A
);

$devHostFixed = 0;
$devHostLinks = 0;
foreach ($devHostRows as $row) {
    $content = (string) preg_replace($devHostPattern, $currentUrl, $row['post_content'], -1, $inContent);
    $excerpt = (string) preg_replace($devHostPattern, $currentUrl, $row['post_excerpt'], -1, $inExcerpt);
    if ($content === $row['post_content'] && $excerpt === $row['post_excerpt']) {
        continue;
    }
    $devHostFixed++;
    $devHostLinks += $inContent + $inExcerpt;
    if (!$dryRun) {
        $wpdb->update(
            $wpdb->posts,
            ['post_content' => $content, 'post_excerpt' => $excerpt],
            ['ID' => (int) $row['ID']]
        );
    }
}

printf(
    "\nADRESSES DE DEV  %4d lien(s) dans %d contenu(s) ramenés à %s\n",
    $devHostLinks,
    $devHostFixed,
    $currentUrl
);

printf("\n--- sub_audit_log n'est jamais modifié par ce script : trace de sécurité, pas une donnée métier. ---\n");

printf("\n%s\n\n", $dryRun
    ? 'Fin de simulation. Relisez la liste ci-dessus, puis relancez avec « write » pour supprimer réellement.'
    : 'Nettoyage terminé sur sub_export. Étape suivante : search-replace de l’URL, puis wp db export.'
);
