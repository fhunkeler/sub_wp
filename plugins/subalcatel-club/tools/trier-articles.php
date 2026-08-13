<?php
/**
 * Tri des articles importés qui ne sont pas des actualités.
 *
 * L'import Joomla a repris 75 articles. 32 d'entre eux ne sont pas éditoriaux :
 * ce sont des gabarits, des textes de modules et de la documentation
 * d'administration d'un CMS que le club n'utilise plus. Ils polluent
 * /actualites/. Cet outil les sort du blog, sans rien détruire d'irremplaçable.
 *
 * Trois sorts, selon ce que dit réellement le contenu :
 *
 *   trash  documentation d'un outil abandonné (Joomla, JCE, CBJuice2,
 *          EventBooking). Sans objet sur le nouveau site. La corbeille
 *          WordPress reste réversible tant qu'elle n'est pas vidée.
 *   draft  texte encore utile — règle métier, message d'interface, mention
 *          légale — mais qui n'a pas sa place dans un fil d'actualités.
 *          Sort du site public, reste consultable en administration.
 *   page   contenu éditorial destiné à une page statique. Converti en page,
 *          en brouillon : le contenu Joomla porte des liens `index.php?option=`
 *          morts, il demande une relecture avant publication.
 *
 * Le tri s'ancre sur l'identifiant Joomla d'origine (`_sub_joomla_article_id`),
 * pas sur l'ID WordPress : un réimport renumérote les articles, pas la source.
 *
 * Simulation par défaut, comme les autres outils de reprise :
 *
 *   wp eval-file wp-content/plugins/subalcatel-club/tools/trier-articles.php
 *   wp eval-file … trier-articles.php write     ← écrit réellement
 */

use Subalcatel\Club\Import\ArticleImporter;

global $wpdb;

require_once __DIR__ . '/bootstrap.php';

$dryRun = sub_import_is_dry_run($args ?? []);

/**
 * Le tri, par identifiant Joomla.
 *
 * @var array<int, array{0: string, 1: string}> action => motif
 */
$decisions = [
    // — Documentation d'administration Joomla : l'outil décrit n'existe plus —
    2   => ['trash', 'Gestion des droits ACL de Joomla'],
    3   => ['trash', 'Paramétrage des droits d\'auteur Joomla'],
    27  => ['trash', 'Import d\'utilisateurs via CBJuice2'],
    44  => ['trash', 'Gabarit d\'article de l\'éditeur JCE'],
    49  => ['trash', 'Rédaction d\'article dans l\'admin Joomla'],
    52  => ['trash', 'Une phrase sur un onglet d\'EventBooking'],
    59  => ['trash', 'Format de date dans l\'export JDBexport'],
    104 => ['trash', 'Réglage d\'un plugin EventBooking'],
    113 => ['trash', 'Boîte d\'archivage Joomla — CONTIENT UN MOT DE PASSE'],

    // — Règles métier toujours en vigueur : à relire, pas à publier —
    42  => ['draft', 'Logique des deux dates de fin d\'adhésion'],
    63  => ['draft', 'Procédure secrétariat : créer un adhérent'],
    123 => ['draft', 'Règles de fonctionnement des sorties'],
    130 => ['draft', 'Règle du certificat médical de moins d\'un an'],

    // — Textes affichés dans des modules : matière à blocs, pas des actualités —
    46  => ['draft', 'Message affiché sur un événement'],
    47  => ['draft', 'Message sur les listes de diffusion'],
    50  => ['draft', 'Message du profil adhérent'],
    96  => ['draft', 'Message profil nouveau / expiré'],
    127 => ['draft', 'Messages du formulaire d\'adhésion'],
    138 => ['draft', 'Messages des inscriptions plongée'],
    139 => ['draft', 'Libellés de champs de formulaire'],
    142 => ['draft', 'Logos du bloc « Nous soutenons » → /partenaires/'],
    93  => ['draft', 'Message d\'adhésion (daté 2030, invisible)'],
    136 => ['draft', 'Message de ré-adhésion (daté 2030, invisible)'],

    // — Mentions légales : les pages du SiteBuilder sont des ébauches, ces
    //   textes en sont la matière. Rien de juridique n'est détruit ici. —
    30  => ['draft', 'Historique CNIL d\'avant 2018 → /mentions-legales/'],
    31  => ['draft', 'Siège, agréments, n° FFESSM → /mentions-legales/'],
    32  => ['draft', 'Politique de confidentialité → /confidentialite/'],
    146 => ['draft', 'Cookies publicitaires : NE PAS reprendre tel quel'],

    // — Communication périmée —
    149 => ['draft', 'Auto-questionnaire Covid de 2020'],

    // — Contenu éditorial : destiné à une page statique. Le titre d'origine
    //   est un nom de module Joomla, il est réécrit pour la page. —
    101 => ['page', 'Conditions générales d\'adhésion'],
    77  => ['page', 'Ce que comprend la cotisation → /adherer/',
            'Ce que comprend l\'adhésion', 'adhesion-contenu'],
    95  => ['page', 'Tarifs réels 2025-2026 → /tarifs/',
            'Tarifs de la saison 2025-2026', 'tarifs-saison-2025-2026'],
    129 => ['page', 'Présentation du club → /nous-rejoindre/',
            'Présentation du club et accès membre', 'presentation-club'],
];

/** Articles dont le contenu porte un secret à retirer avant tout. */
$aExpurger = [113];

// ---------------------------------------------------------------------------

$meta  = ArticleImporter::JOOMLA_ID_META;
// Les pages sont incluses : un article déjà converti doit être reconnu comme
// trié, pas signalé comme disparu quand l'outil est rejoué.
$lignes = $wpdb->get_results(
    "SELECT p.ID, p.post_title, p.post_status, p.post_type, m.meta_value AS legacy_id
     FROM {$wpdb->posts} p
     JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '{$meta}'
     WHERE p.post_type IN ('post', 'page')",
    ARRAY_A
) ?: [];

/** @var array<int, array<string, mixed>> */
$parLegacy = [];
foreach ($lignes as $ligne) {
    $parLegacy[(int) $ligne['legacy_id']] = $ligne;
}

printf("%s\n\n", $dryRun ? '=== SIMULATION — aucune écriture ===' : '=== TRI RÉEL ===');

$compte    = ['trash' => 0, 'draft' => 0, 'page' => 0];
$introuvables = [];
$dejaFait  = 0;
$retiresDuBlog = 0; // Ceux qui étaient réellement visibles sur /actualites/.

foreach ($decisions as $legacyId => $decision) {
    [$action, $motif] = $decision;
    $nouveauTitre = $decision[2] ?? null;
    $nouveauSlug  = $decision[3] ?? null;

    if (!isset($parLegacy[$legacyId])) {
        $introuvables[] = $legacyId;
        continue;
    }

    $article = $parLegacy[$legacyId];
    $postId  = (int) $article['ID'];
    $titre   = (string) $article['post_title'];

    // Un article déjà trié ne doit pas être retouché : le tri est rejouable.
    // Seul le renommage d'une page peut rester à faire, si le tri a tourné
    // avant que les titres de destination ne soient définis.
    if (in_array($article['post_status'], ['trash', 'draft'], true)) {
        $dejaFait++;

        $aRenommer = $nouveauTitre !== null
            && $article['post_type'] === 'page'
            && get_post_meta($postId, '_sub_tri_titre_origine', true) === '';

        if (!$aRenommer) {
            continue;
        }

        printf("  RENOM #%-4d %s\n         → « %s » (/%s/)\n", $postId, mb_substr($titre, 0, 52), $nouveauTitre, $nouveauSlug);

        if (!$dryRun) {
            update_post_meta($postId, '_sub_tri_titre_origine', $titre);
            wp_update_post([
                'ID'         => $postId,
                'post_title' => $nouveauTitre,
                'post_name'  => $nouveauSlug,
            ]);
        }

        continue;
    }

    $categorie = '';
    $termes = wp_get_object_terms($postId, 'category', ['fields' => 'names']);
    if (!is_wp_error($termes) && $termes !== []) {
        $categorie = (string) $termes[0];
    }

    printf(
        "  %-5s #%-4d %-52s\n         %s\n",
        strtoupper($action),
        $postId,
        mb_substr($titre, 0, 52),
        $motif
    );

    $compte[$action]++;

    if ($article['post_status'] === 'publish') {
        $retiresDuBlog++;
    }

    if ($dryRun) {
        continue;
    }

    // Le secret part avant tout le reste : la corbeille n'efface pas la base.
    if (in_array($legacyId, $aExpurger, true)) {
        $contenu = (string) get_post_field('post_content', $postId);
        $propre  = preg_replace(
            '/(Mot de passe\s*(?:&nbsp;)?\s*:\s*(?:&nbsp;)?\s*)[^<\r\n]+/iu',
            '$1[retiré lors du tri — identifiant à révoquer chez l\'hébergeur]',
            $contenu
        );

        if ($propre !== null && $propre !== $contenu) {
            wp_update_post(['ID' => $postId, 'post_content' => $propre]);
            printf("         ↳ mot de passe retiré du contenu\n");
        }
    }

    update_post_meta($postId, '_sub_tri_action', $action);
    update_post_meta($postId, '_sub_tri_categorie_origine', $categorie);

    if ($action === 'trash') {
        wp_trash_post($postId);
        continue;
    }

    if ($action === 'draft') {
        wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
        continue;
    }

    // Conversion en page : une page ne porte pas de catégorie, l'origine est
    // conservée en méta pour rester réversible.
    $modifs = [
        'ID'          => $postId,
        'post_type'   => 'page',
        'post_status' => 'draft',
    ];

    if ($nouveauTitre !== null) {
        $modifs['post_title'] = $nouveauTitre;
        $modifs['post_name']  = $nouveauSlug;
        update_post_meta($postId, '_sub_tri_titre_origine', $titre);
        printf("         ↳ renommée « %s » (/%s/)\n", $nouveauTitre, $nouveauSlug);
    }

    wp_update_post($modifs);
    wp_set_object_terms($postId, [], 'category');
}

printf(
    "\n  corbeille %d   brouillon %d   page %d",
    $compte['trash'],
    $compte['draft'],
    $compte['page']
);

if ($dejaFait > 0) {
    printf("   (déjà triés : %d)", $dejaFait);
}

if ($introuvables !== []) {
    printf("\n\n  ⚠ introuvables en base : %s", implode(', ', $introuvables));
}

// Ce qui reste effectivement visible sur /actualites/.
$publies = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'"
);

printf(
    "\n  articles publiés : %d → %d\n",
    $dryRun ? $publies : $publies + $retiresDuBlog,
    $dryRun ? $publies - $retiresDuBlog : $publies
);
