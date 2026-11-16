<?php
/**
 * Test de fumée des documents du club et de la visibilité des contenus.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-content.php
 *
 * Ce qui compte : un document réservé ne sort pas, un contenu réservé
 * n'apparaît pas dans les listes, et le remplacement d'un fichier n'efface
 * jamais le précédent.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Content\ClubDocuments;
use Subalcatel\Club\Content\DocumentLibrary;
use Subalcatel\Club\Content\Visibility;
use Subalcatel\Club\Documents\DocumentStorage;

global $wpdb;

ClubDocuments::register();
ClubDocuments::seedCategories();

$library  = new DocumentLibrary();
$failures = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role, bool $upToDate): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);

    if ($upToDate) {
        update_user_meta($id, 'sub_membership_valid_until', '2027-12-31');
    }

    return $id;
};

$member  = $makeUser('sub_member', true);
$expired = $makeUser('sub_member', false);
$office  = $makeUser('sub_office', true);

$makeDoc = static function (string $title, string $access, string $capability = ''): int {
    $id = wp_insert_post([
        'post_type'    => ClubDocuments::POST_TYPE,
        'post_title'   => $title,
        'post_content' => 'Description du document.',
        'post_status'  => 'publish',
    ]);

    update_post_meta($id, ClubDocuments::META_ACCESS, $access);

    if ($capability !== '') {
        update_post_meta($id, ClubDocuments::META_CAPABILITY, $capability);
    }

    return $id;
};

$public    = $makeDoc('Statuts de l’association', ClubDocuments::ACCESS_PUBLIC);
$reserved  = $makeDoc('Compte rendu AG 2026', ClubDocuments::ACCESS_MEMBERS);
$restricted = $makeDoc('Consignes compresseur', ClubDocuments::ACCESS_CAPABILITY, 'sub_manage_equipment');

foreach ([$public, $reserved, $restricted] as $id) {
    $library->attach($id, sub_test_upload('doc.pdf'), $office);
}

// --- Qui voit quoi -----------------------------------------------------------
echo "\n--- Droits d’accès aux documents ---\n";

$check('Document public : visiteur non connecté', ClubDocuments::mayDownload($public, 0));
$check('Document réservé : visiteur refusé', !ClubDocuments::mayDownload($reserved, 0));
$check('Document réservé : adhérent à jour', ClubDocuments::mayDownload($reserved, $member));
$check('Document réservé : adhésion expirée refusée', !ClubDocuments::mayDownload($reserved, $expired),
    'être inscrit ne suffit pas, il faut être à jour');

$check('Document restreint : adhérent sans la capacité refusé',
    !ClubDocuments::mayDownload($restricted, $member));

get_userdata($member)->add_cap('sub_manage_equipment');
wp_cache_delete($member, 'users');
$check('Document restreint : avec la capacité, autorisé',
    ClubDocuments::mayDownload($restricted, $member));

$check('Le bureau accède à tout', ClubDocuments::mayDownload($restricted, $office)
    && ClubDocuments::mayDownload($reserved, $office), 'il produit ces documents');

// --- Capacités du type de publication ----------------------------------------
echo "\n--- Droits du bureau sur le type de publication ---\n";

$check('Le bureau garde sa capacité de contenu', user_can($office, 'sub_manage_content'),
    'un mappage trop zélé la détournait vers edit_post');

global $post_type_meta_caps;
$check('sub_manage_content n’est pas devenue une méta-capacité',
    !isset($post_type_meta_caps['sub_manage_content']),
    'sinon tout contrôle de cette capacité échoue, partout, sans erreur');

$check('Le bureau modifie un document publié', user_can($office, 'edit_post', $reserved),
    'edit_published_posts, pas seulement edit_posts');
$check('Un adhérent ordinaire ne le modifie pas', !user_can($member, 'edit_post', $reserved));

// --- Téléchargement ----------------------------------------------------------
echo "\n--- Téléchargement ---\n";

$file = $library->download($public, 0);
$check('Le fichier est servi', str_starts_with($file['contents'], '%PDF'), $file['filename']);
$check('Type MIME correct', $file['mime'] === 'application/pdf');
$check('Compteur incrémenté',
    (int) get_post_meta($public, ClubDocuments::META_DOWNLOADS, true) === 1);

try {
    $library->download($reserved, $expired);
    $check('Refus motivé pour une adhésion expirée', false);
} catch (RuntimeException $e) {
    $check('Refus motivé pour une adhésion expirée', true, $e->getMessage());
}

// --- Versions ----------------------------------------------------------------
echo "\n--- Historique des versions ---\n";

$firstKey = (string) get_post_meta($reserved, ClubDocuments::META_KEY, true);

$library->attach($reserved, sub_test_upload('ag-2026-corrige.pdf'), $office);

$versions = (array) get_post_meta($reserved, ClubDocuments::META_VERSIONS, true);
$check('La version précédente est archivée', count($versions) === 1);
$check('Son fichier existe toujours', DocumentStorage::exists($firstKey),
    'un compte rendu corrigé laisse trace de ce qui avait été diffusé');
$check('Le fichier courant a changé',
    (string) get_post_meta($reserved, ClubDocuments::META_KEY, true) !== $firstKey);
$check('Le nom affiché suit', get_post_meta($reserved, ClubDocuments::META_FILENAME, true) === 'ag-2026-corrige.pdf');

$library->restoreVersion($reserved, 0, $office);
$check('Restauration : on retrouve le fichier d’origine',
    (string) get_post_meta($reserved, ClubDocuments::META_KEY, true) === $firstKey);
$check('L’échange est symétrique, rien n’est perdu',
    count((array) get_post_meta($reserved, ClubDocuments::META_VERSIONS, true)) === 1);

// --- Contrôle des dépôts -----------------------------------------------------
echo "\n--- Contrôle des fichiers déposés ---\n";

try {
    $library->attach($public, sub_test_upload('malveillant.php', '<?php echo 1;'), $office);
    $check('Un .php est refusé', false);
} catch (RuntimeException $e) {
    $check('Un .php est refusé', true, $e->getMessage());
}

try {
    $library->attach($public, sub_test_upload('faux.pdf', '<?php echo 1;'), $office);
    $check('Un contenu qui ment sur son extension est refusé', false);
} catch (RuntimeException $e) {
    $check('Un contenu qui ment sur son extension est refusé', true,
        'c’est par là que le Joomla est tombé');
}

// --- Parcours ----------------------------------------------------------------
echo "\n--- Liste consultable ---\n";

wp_set_object_terms($public, 'statuts-reglements', ClubDocuments::TAXONOMY);

$titlesFor = static function (int $userId) use ($library): array {
    $titles = [];

    foreach ($library->browse($userId) as $group) {
        foreach ($group['documents'] as $document) {
            $titles[] = $document->post_title;
        }
    }

    return $titles;
};

$visitorSees = $titlesFor(0);
$check('Le visiteur ne voit que le public', in_array('Statuts de l’association', $visitorSees, true)
    && !in_array('Compte rendu AG 2026', $visitorSees, true), count($visitorSees) . ' document(s)');

$officeSees = $titlesFor($office);
$check('Le bureau voit tout', count($officeSees) >= 3, count($officeSees) . ' document(s)');

$check('La recherche filtre', $library->browse($office, 'compresseur') !== []
    && count($library->browse($office, 'compresseur')[0]['documents']) === 1);

// --- Arborescence ------------------------------------------------------------
echo "\n--- Arborescence des catégories ---\n";

$tax       = ClubDocuments::TAXONOMY;
$formation = (int) wp_insert_term('Formation test', $tax, ['slug' => 'formation-test'])['term_id'];
$level1    = (int) wp_insert_term('Niveau 1 test', $tax, ['slug' => 'niveau-1-test', 'parent' => $formation])['term_id'];
$level2    = (int) wp_insert_term('Niveau 2 test', $tax, ['slug' => 'niveau-2-test', 'parent' => $formation])['term_id'];
$level10   = (int) wp_insert_term('Niveau 10 test', $tax, ['slug' => 'niveau-10-test', 'parent' => $formation])['term_id'];

$treeDocs = [
    'rous'    => [$makeDoc('Support RIFAP N1', ClubDocuments::ACCESS_PUBLIC), [$level1]],
    'both'    => [$makeDoc('Programme N1', ClubDocuments::ACCESS_PUBLIC), [$formation, $level1]],
    'level2'  => [$makeDoc('Support N2', ClubDocuments::ACCESS_MEMBERS), [$level2]],
    'level10' => [$makeDoc('Support N10', ClubDocuments::ACCESS_PUBLIC), [$level10]],
];

foreach ($treeDocs as [$id, $terms]) {
    wp_set_object_terms($id, $terms, $tax);
}

$findNode = static function (array $nodes, string $slug) use (&$findNode): ?array {
    foreach ($nodes as $node) {
        if (($node['term']?->slug ?? '') === $slug) {
            return $node;
        }

        $found = $findNode($node['children'], $slug);

        if ($found !== null) {
            return $found;
        }
    }

    return null;
};

$childSlugs = static fn (?array $node): array
    => array_map(static fn (array $c): string => $c['term']->slug, $node['children'] ?? []);

$visitorTree = $library->tree(0);
$memberTree  = $library->tree($member);

$check('La sous-catégorie est rangée sous sa parente',
    $childSlugs($findNode($visitorTree, 'formation-test')) === ['niveau-1-test', 'niveau-10-test'],
    implode(', ', $childSlugs($findNode($visitorTree, 'formation-test'))));
$check('Une branche sans document visible disparaît',
    $findNode($visitorTree, 'niveau-2-test') === null, 'le Niveau 2 est réservé aux adhérents');
$check('L’adhérent voit la branche réservée, en ordre naturel',
    $childSlugs($findNode($memberTree, 'formation-test')) === ['niveau-1-test', 'niveau-2-test', 'niveau-10-test'],
    'Niveau 2 avant Niveau 10');
$check('Parente et fille cochées : rangé sous la fille seule',
    ($findNode($visitorTree, 'formation-test')['documents'] ?? null) === []
    && count($findNode($visitorTree, 'niveau-1-test')['documents'] ?? []) === 2);
$check('Le compteur inclut les sous-catégories',
    ($findNode($visitorTree, 'formation-test')['count'] ?? 0) === 3
    && ($findNode($memberTree, 'formation-test')['count'] ?? 0) === 4);
$check('Les catégories vides n’apparaissent pas', $findNode($visitorTree, 'comptes-rendus') === null);

$last = end($memberTree);
$check('Les documents sans catégorie ferment la marche', $last['term'] === null
    && in_array('Compte rendu AG 2026', array_map(static fn ($d) => $d->post_title, $last['documents']), true));

$searched = $library->tree(0, 'RIFAP');
$check('La recherche garde le chemin de la catégorie',
    count($searched) === 1 && $childSlugs($searched[0]) === ['niveau-1-test']);

// --- Pas de limite de nombre ---------------------------------------------------
$bulk = [];

for ($i = 0; $i < 205; $i++) {
    $bulk[] = $makeDoc(sprintf('Archive Joomla %03d', $i), ClubDocuments::ACCESS_PUBLIC);
}

$visibleCount = 0;

foreach ($library->tree(0) as $node) {
    $visibleCount += $node['count'];
}

$check('Plus de 200 documents : aucun ne manque', $visibleCount >= 205 + 4,
    $visibleCount . ' document(s) dans l’arbre');

foreach ($bulk as $id) {
    wp_delete_post($id, true);
}

foreach ($treeDocs as [$id]) {
    wp_delete_post($id, true);
}

foreach ([$level1, $level2, $level10, $formation] as $termId) {
    wp_delete_term($termId, $tax);
}

// --- Visibilité des articles -------------------------------------------------
echo "\n--- Visibilité des articles ---\n";

$publicPost = wp_insert_post([
    'post_type' => 'post', 'post_title' => 'Sortie à Trébeurden', 'post_status' => 'publish',
]);
$memberPost = wp_insert_post([
    'post_type' => 'post', 'post_title' => 'Compte rendu du bureau', 'post_status' => 'publish',
]);

update_post_meta($memberPost, Visibility::META, Visibility::MEMBERS_ONLY);

$check('Article public lisible par tous', Visibility::mayRead($publicPost, 0));
$check('Article réservé : visiteur refusé', !Visibility::mayRead($memberPost, 0));
$check('Article réservé : adhérent à jour', Visibility::mayRead($memberPost, $member));
$check('Article réservé : adhésion expirée refusée', !Visibility::mayRead($memberPost, $expired));
$check('Le bureau lit tout', Visibility::mayRead($memberPost, $office));

// Le statut « privé » de WordPress ne convenait pas : il est réservé aux
// éditeurs. On vérifie que l'article reste bien publié, donc accessible aux
// adhérents ordinaires, et protégé par la méta seule.
$check('L’article reste au statut « publié »', get_post_status($memberPost) === 'publish',
    'le statut privé de WordPress aurait exclu les adhérents');

// --- Exclusion des listes ----------------------------------------------------
echo "\n--- Retrait des listes publiques ---\n";

$runQuery = static function (): array {
    $query = new WP_Query(['post_type' => 'post', 'posts_per_page' => 50, 'fields' => 'ids']);
    Visibility::filterQueries($query);
    $query->query($query->query_vars);

    return array_map('intval', $query->posts);
};

wp_set_current_user(0);
$anonymousSees = $runQuery();
$check('Le visiteur ne voit pas l’article réservé', !in_array($memberPost, $anonymousSees, true));
$check('Il voit bien l’article public', in_array($publicPost, $anonymousSees, true),
    count($anonymousSees) . ' article(s)');

wp_set_current_user($member);
$memberSees = $runQuery();
$check('L’adhérent voit les deux', in_array($memberPost, $memberSees, true)
    && in_array($publicPost, $memberSees, true));

wp_set_current_user(0);

// --- Commentaires ------------------------------------------------------------
echo "\n--- Commentaires ---\n";

$check('Commentaires fermés', !comments_open($publicPost), 'blog sans commentaires');

// --- Nettoyage ---------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ([$public, $reserved, $restricted] as $id) {
    $library->purgeFiles($id);
    wp_delete_post($id, true);
}

foreach ([$publicPost, $memberPost] as $id) {
    wp_delete_post($id, true);
}

foreach ([$member, $expired, $office] as $id) {
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
