<?php
/**
 * Test de fumée du calendrier, de l'iCal et du tableau de bord.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-calendar.php
 *
 * L'iCal est le morceau à surveiller : un client de calendrier qui n'aime pas
 * le flux ne dit rien, il affiche un agenda vide. Chaque règle de la RFC 5545
 * qu'on pourrait croire cosmétique est donc vérifiée ici.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Admin\DashboardScreen;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Events\IcalFeed;
use Subalcatel\Club\Events\IcalWriter;
use Subalcatel\Club\Identity\DiveLevels;

global $wpdb;

EventTypeSeeder::run();

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

add_filter('pre_wp_mail', '__return_true');

$makeUser = static function (string $role, string $levelSlug): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);

    $term = get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);
    sub_test_make_compliant($id);

    return $id;
};

$organiser = $makeUser('sub_office', 'p5');
$member    = $makeUser('sub_member', 'p3');
get_userdata($organiser)->add_cap('sub_create_exploration_event');

$service = new EventService();

$eventId = $service->create('plongee-exploration', [
    'title'           => 'Sortie « Squewel » ; test, avec virgule',
    'description'     => "Rendez-vous au local.\nPrévoir de quoi déjeuner.",
    'starts_at'       => gmdate('Y-m-d 08:30:00', strtotime('+10 days')),
    'ends_at'         => gmdate('Y-m-d 17:00:00', strtotime('+10 days')),
    'location'        => 'Ploumanac’h',
    'capacity'        => 6,
    'accepted_levels' => ['p1', 'p3', 'p5'],
], $organiser);

$event = $service->find($eventId);

// --- Format iCalendar --------------------------------------------------------
echo "\n--- Conformité du flux iCalendar ---\n";

$ics = IcalWriter::calendar('Agenda Sub Alcatel', 'Les sorties du club', [IcalWriter::event($event)], 720);

$check('Enveloppe complète',
    str_starts_with($ics, 'BEGIN:VCALENDAR') && str_ends_with($ics, "END:VCALENDAR\r\n"));
$check('Version annoncée', str_contains($ics, 'VERSION:2.0'));

// Toutes les fins de ligne doivent être CRLF : un « \n » isolé fait rejeter le
// fichier par une partie des clients, en silence.
$check('Aucun saut de ligne isolé',
    preg_match('/(?<!\r)\n/', $ics) === 0, 'CRLF partout, sinon rejet silencieux');

$lines = explode("\r\n", rtrim($ics, "\r\n"));
$tooLong = array_filter($lines, static fn (string $l): bool => strlen($l) > 75);
$check('Aucune ligne au-delà de 75 octets', $tooLong === [],
    $tooLong === [] ? '' : 'la plus longue : ' . max(array_map('strlen', $tooLong)));

$check('Les caractères accentués survivent au pliage',
    str_contains(str_replace("\r\n ", '', $ics), 'Ploumanac’h'),
    'replier au milieu d’un « ’ » casserait l’UTF-8');

// --- Échappement -------------------------------------------------------------
echo "\n--- Échappement ---\n";

$unfolded = str_replace("\r\n ", '', $ics);

$check('Le point-virgule est échappé', str_contains($unfolded, '\\;'));
$check('La virgule est échappée', str_contains($unfolded, '\\,'));
$check('Le retour à la ligne devient \\n', str_contains($unfolded, 'Prévoir de quoi'));
$check('La description ne contient pas de vrai retour',
    !str_contains($unfolded, "local.\r\nPrévoir"));

$check('Contre-oblique échappée en premier',
    IcalWriter::escape('a\\;b') === 'a\\\\\\;b', IcalWriter::escape('a\\;b'));

// --- Identifiants et fuseau --------------------------------------------------
echo "\n--- Identifiants et fuseau ---\n";

$check('UID stable entre deux générations',
    IcalWriter::uid($eventId) === IcalWriter::uid($eventId), 'sinon le client crée un doublon');
$check('UID propre au site', str_contains(IcalWriter::uid($eventId), '@'));
$check('UID distinct par événement', IcalWriter::uid($eventId) !== IcalWriter::uid($eventId + 1));

$check('Les dates sont en UTC', str_ends_with(IcalWriter::utc('2026-08-15 08:30:00'), 'Z'));

// Le site est en Europe/Paris : 8 h 30 locales en août = 6 h 30 UTC.
$paris = new DateTimeZone('Europe/Paris');
$expected = (new DateTimeImmutable('2026-08-15 08:30:00', $paris))
    ->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

$check('Conversion depuis le fuseau du site',
    IcalWriter::utc('2026-08-15 08:30:00') === $expected,
    IcalWriter::utc('2026-08-15 08:30:00') . ' au lieu de 20260815T083000Z');

$properties = IcalWriter::event($event);
$check('Heure de fin reprise de l’événement',
    $properties['DTEND'] === IcalWriter::utc((string) $event['ends_at']));

$noEnd = $event;
$noEnd['ends_at'] = null;
$check('Sans heure de fin, deux heures par défaut',
    IcalWriter::event($noEnd)['DTEND'] === IcalWriter::utc((string) $event['starts_at'], '+2 hours'),
    'un événement de durée nulle est illisible dans un agenda');

// --- Jetons d’abonnement -----------------------------------------------------
echo "\n--- Abonnement ---\n";

$token = IcalFeed::token($member);

$check('Jeton créé à la demande', $token !== '');
$check('Jeton stable', IcalFeed::token($member) === $token, 'sinon l’abonnement casse tout seul');
$check('Jeton propre au compte', IcalFeed::token($organiser) !== $token);

$check('Distinct du jeton de la lettre d’information',
    $token !== (string) get_user_meta($member, 'sub_newsletter_token', true),
    'une fuite de l’un ne doit pas emporter l’autre');

$url = IcalFeed::feedUrl($member, IcalFeed::FEED_CLUB);
$check('L’URL porte le jeton', str_contains($url, 'token=' . $token));

$check('L’URL webcal ouvre l’application de calendrier',
    str_starts_with(IcalFeed::subscribeUrl($member, IcalFeed::FEED_CLUB), 'webcal://'));

IcalFeed::token($member);
delete_user_meta($member, IcalFeed::META_TOKEN);
$check('Le renouvellement invalide l’ancienne adresse',
    IcalFeed::token($member) !== $token, 'une URL qui a fui doit pouvoir être révoquée');

// --- Liste d’attente dans l’agenda -------------------------------------------
echo "\n--- Inscription en liste d’attente ---\n";

$confirmed = IcalFeed::propertiesFor($event + ['registration_status' => 'confirmed']);
$waiting   = IcalFeed::propertiesFor($event + ['registration_status' => 'waiting']);

$check('Une inscription confirmée est ferme',
    $confirmed['STATUS'] === 'CONFIRMED' && $confirmed['TRANSP'] === 'OPAQUE');
$check('Une place en attente est provisoire', $waiting['STATUS'] === 'TENTATIVE');
$check('Elle ne bloque pas le créneau', $waiting['TRANSP'] === 'TRANSPARENT',
    'le membre doit pouvoir accepter autre chose au même moment');
$check('Le titre l’annonce', str_contains($waiting['SUMMARY'], 'Liste d'));

// --- Calendrier mensuel ------------------------------------------------------
echo "\n--- Calendrier mensuel ---\n";

$html = do_shortcode('[subalcatel_calendrier]');

$check('Le shortcode rend quelque chose', str_contains($html, 'sub-calendar'));
$check('La grille est présente', str_contains($html, 'sub-calendar__grid'));
$check('La liste de repli aussi', str_contains($html, 'sub-calendar__list'),
    'sept colonnes à 375 px seraient illisibles');
$check('Navigation sans JavaScript',
    substr_count($html, 'sub-calendar__nav') === 2, 'chaque flèche est un lien');

$monthOfEvent = gmdate('Y-m', strtotime('+10 days'));
$_GET['mois']  = $monthOfEvent;
$htmlMonth     = do_shortcode('[subalcatel_calendrier]');

$check('La sortie apparaît dans son mois',
    str_contains($htmlMonth, 'Sortie'), $monthOfEvent);
$check('Le lien pointe l’ancre de l’agenda',
    str_contains($htmlMonth, '#sub-event-' . $eventId));

$_GET['mois'] = 'n-importe-quoi';
$check('Un mois fantaisiste ne casse rien',
    str_contains(do_shortcode('[subalcatel_calendrier]'), 'sub-calendar__grid'),
    'ce paramètre est public, il sera trituré');
unset($_GET['mois']);

// --- Tableau de bord ---------------------------------------------------------
echo "\n--- Tableau de bord bureau ---\n";

wp_set_current_user($organiser);
$blocks = DashboardScreen::blocks();
$titles = array_column($blocks, 'title');

$check('Des blocs sont produits', $blocks !== [], count($blocks) . ' bloc(s)');
$check('Les prochaines sorties y figurent',
    in_array('Prochaines sorties', $titles, true));

$upcoming = array_values(array_filter($blocks, static fn (array $b): bool => $b['title'] === 'Prochaines sorties'))[0];
$check('La sortie créée y apparaît',
    str_contains(wp_json_encode($upcoming['items']), 'Squewel'));
$check('Chaque entrée mène à un écran', $upcoming['items'][0]['url'] !== '');

// Le filtrage par capacité est le point sensible : un bloc sans objet pour la
// personne doit être absent, pas seulement masqué en CSS.
wp_set_current_user($member);
$memberTitles = array_column(DashboardScreen::blocks(), 'title');

$check('Un adhérent ne voit pas les règlements attendus',
    !in_array('Règlements attendus', $memberTitles, true));
$check('Ni les dossiers à valider',
    !in_array('Dossiers à valider', $memberTitles, true));
$check('Il voit tout de même les sorties',
    in_array('Prochaines sorties', $memberTitles, true));

$anyClubCap = static function (int $userId): bool {
    foreach (array_keys(\Subalcatel\Club\Identity\Roles::CAPABILITIES) as $capability) {
        if (user_can($userId, $capability)) {
            return true;
        }
    }

    return false;
};

// Un P3 à jour détient désormais `sub_create_exploration_event`, déduit de son
// niveau : proposer une sortie n'est pas une fonction au bureau. Ce qu'on
// vérifie ici n'est donc plus « aucune capacité », mais la porte du menu
// « Club » — gardée par `sub_manage_memberships`, jamais déduite d'un niveau.
$check('L’adhérent n’a pas accès à l’écran', !user_can($member, 'sub_manage_memberships'),
    'le menu n’est pas seulement masqué, il n’est pas déclaré');
$check('Il ne valide ni compte ni document',
    !user_can($member, 'sub_validate_account') && !user_can($member, 'sub_validate_member_document'));
$check('Le bureau y a accès', $anyClubCap($organiser));

wp_set_current_user($organiser);
$stats = DashboardScreen::stats();

$check('Statistiques produites', $stats !== [], implode(', ', array_keys($stats)));
$check('Les certificats manquants sont calculés',
    array_key_exists('Certificats manquants', $stats),
    'l’écart avec les adhérents à jour est l’information utile');
$check('Aucun compteur négatif',
    array_filter($stats, static fn (int $v): bool => $v < 0) === []);

wp_set_current_user(0);

// --- Nettoyage ---------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';

$wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $eventId]);
$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);

foreach ([$organiser, $member] as $id) {
    sub_test_clean_documents($id);
    $wpdb->delete("{$wpdb->prefix}sub_notification_log", ['recipient_id' => $id]);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
