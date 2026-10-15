<?php
/**
 * Test de fumée — documents joints à un événement.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-event-documents.php
 *
 * Une AG se prépare avec une convocation, un ordre du jour, un pouvoir ; un
 * stage avec un programme. Ce sont des documents du club, rattachés à
 * l'événement. Ce qui doit tenir :
 *
 *   - chacun ne voit dans le détail de l'événement que ce qu'il a le droit
 *     de télécharger, et jamais un brouillon ;
 *   - une pièce d'AG sans catégorie rejoint « Assemblées générales » ;
 *   - supprimer l'événement ne supprime pas ses documents.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\ClubDocumentsScreen;
use Subalcatel\Club\Admin\EventsScreen;
use Subalcatel\Club\Content\ClubDocuments;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Frontend\EventDetails;
use Subalcatel\Club\Identity\Roles;

global $wpdb;

ClubDocuments::register();
ClubDocuments::seedCategories();
EventTypeSeeder::run();

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$user = static function (string $role): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => strtolower(wp_generate_password(8, false)) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);
    update_user_meta($id, 'sub_membership_valid_until', '2099-12-31');

    return $id;
};

$office = $user(Roles::OFFICE);
$member = $user(Roles::MEMBER);

$service = new EventService();
$agId    = $service->create('assemblee-generale', [
    'title'     => 'AG — contrôle des documents',
    'starts_at' => gmdate('Y-m-d H:i:s', time() + 30 * 86400),
], $office);
$diveId  = $service->create('seance-piscine', [
    'title'     => 'Piscine — contrôle des documents',
    'starts_at' => gmdate('Y-m-d H:i:s', time() + 3 * 86400),
], 1); // l'administrateur : le bureau n'ouvre pas de séance piscine

$doc = static function (string $title, string $access, string $status = 'publish', string $cap = '') use ($agId): int {
    $id = wp_insert_post([
        'post_type'   => ClubDocuments::POST_TYPE,
        'post_title'  => $title,
        'post_status' => $status,
    ]);
    update_post_meta($id, ClubDocuments::META_ACCESS, $access);
    update_post_meta($id, ClubDocuments::META_FILENAME, sanitize_title($title) . '.pdf');
    update_post_meta($id, ClubDocuments::META_SIZE, 12345);
    if ($cap !== '') {
        update_post_meta($id, ClubDocuments::META_CAPABILITY, $cap);
    }
    ClubDocuments::linkToEvent($id, $agId);

    return $id;
};

$convocation = $doc('Convocation', ClubDocuments::ACCESS_PUBLIC);
$ordre       = $doc('Ordre du jour', ClubDocuments::ACCESS_MEMBERS);
$budget      = $doc('Budget de travail', ClubDocuments::ACCESS_CAPABILITY, 'publish', 'sub_manage_content');
$brouillon   = $doc('Pouvoir — brouillon', ClubDocuments::ACCESS_PUBLIC, 'draft');

// --- Rattachement --------------------------------------------------------------
echo "\n--- Rattachement ---\n";

$ids = array_map(static fn (WP_Post $p): int => $p->ID, ClubDocuments::forEvent($agId));
$check('Les documents publiés de l’AG, dans l’ordre de dépôt',
    $ids === [$convocation, $ordre, $budget], implode(',', $ids));
$check('Le brouillon n’en fait pas partie', !in_array($brouillon, $ids, true));
$check('La séance piscine n’a aucun document', ClubDocuments::forEvent($diveId) === []);

// --- Ce que chacun voit --------------------------------------------------------
echo "\n--- Détail de l’événement ---\n";

$render = static function (int $eventId, int $userId) use ($service): string {
    wp_set_current_user($userId);
    ob_start();
    EventDetails::render($service, $service->find($eventId));

    return (string) ob_get_clean();
};

$html = $render($agId, $member);
$check('L’adhérent voit la convocation et l’ordre du jour',
    str_contains($html, 'Convocation') && str_contains($html, 'Ordre du jour'));
$check('… pas le document réservé au bureau', !str_contains($html, 'Budget de travail'));
$check('… ni le brouillon', !str_contains($html, 'brouillon'));
$check('Le résumé annonce deux documents, et parle d’événement',
    str_contains($html, 'Détails de l’événement · 2 documents'));

$html = $render($agId, $office);
$check('Le bureau voit les trois', str_contains($html, 'Budget de travail')
    && str_contains($html, 'Détails de l’événement · 3 documents'));

$html = $render($diveId, $member);
$check('Une séance sans document : rien de plus qu’avant',
    !str_contains($html, 'sub-event__documents') && !str_contains($html, 'document'));

// --- Écran de l'événement ------------------------------------------------------
echo "\n--- Écran de l’événement ---\n";

wp_set_current_user($office);
$_GET['event_id'] = $agId;
ob_start();
EventsScreen::renderRoster();
$roster = (string) ob_get_clean();
unset($_GET['event_id']);

$check('Les documents y figurent', str_contains($roster, 'Ordre du jour'));
$check('Le bureau peut en ajouter, rattaché d’office',
    str_contains($roster, 'post_type=' . ClubDocuments::POST_TYPE . '&amp;sub_event=' . $agId)
    || str_contains($roster, 'post_type=' . ClubDocuments::POST_TYPE . '&#038;sub_event=' . $agId));

// --- Enregistrement depuis l'écran du document ---------------------------------
echo "\n--- Enregistrement ---\n";

$nouveau = wp_insert_post([
    'post_type'   => ClubDocuments::POST_TYPE,
    'post_title'  => 'Pouvoir',
    'post_status' => 'publish',
]);

$save = static function (int $postId, int $eventId): void {
    $_POST = [
        'sub_club_doc_nonce' => wp_create_nonce('sub_club_doc_' . $postId),
        'sub_doc_access'     => ClubDocuments::ACCESS_MEMBERS,
        'sub_doc_event'      => (string) $eventId,
    ];
    ClubDocumentsScreen::save($postId, get_post($postId));
    $_POST = [];
};

$save($nouveau, $agId);
$terms = wp_get_object_terms($nouveau, ClubDocuments::TAXONOMY, ['fields' => 'slugs']);
$check('Rattaché à l’AG', (int) get_post_meta($nouveau, ClubDocuments::META_EVENT, true) === $agId);
$check('Rangé dans « Assemblées générales »', $terms === ['assemblees-generales'], implode(',', $terms));

$save($nouveau, 999999999);
$check('Un événement inexistant détache le document',
    get_post_meta($nouveau, ClubDocuments::META_EVENT, true) === '');

// --- Suppression de l'événement ------------------------------------------------
echo "\n--- Suppression de l’événement ---\n";

$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $agId]);
ClubDocuments::detachEvent($agId);

$check('Les documents survivent à l’événement',
    get_post($convocation) instanceof WP_Post && get_post($ordre) instanceof WP_Post);
$check('… détachés', ClubDocuments::forEvent($agId) === []
    && get_post_meta($ordre, ClubDocuments::META_EVENT, true) === '');

// --- Nettoyage -----------------------------------------------------------------
wp_set_current_user(0);
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ([$convocation, $ordre, $budget, $brouillon, $nouveau] as $id) {
    wp_delete_post($id, true);
}

$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $diveId]);

foreach ([$office, $member] as $id) {
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
