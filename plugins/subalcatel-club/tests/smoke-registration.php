<?php
/**
 * Test de fumée du formulaire d'inscription aux sorties.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-registration.php
 *
 * Ce qui compte : les champs suivent le type d'événement, les réponses sont
 * figées dans l'inscription, et rien d'inattendu n'entre en base.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Events\RegistrationFields;
use Subalcatel\Club\Identity\DiveLevels;

EventTypeSeeder::run();

$service  = new EventService();
$failures = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-54s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeDiver = static function (string $levelSlug): int {
    $login = 'demo_' . wp_generate_password(8, false);
    $id    = wp_insert_user([
        'user_login' => $login,
        // Sans adresse, tout envoi échoue en silence : `Mailer::toUser` rend la
        // main sans rien faire, et un test d'envoi devient vacuement vert.
        'user_email' => $login . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'role'       => 'sub_member',
    ]);

    update_user_meta($id, 'sub_dive_level_id', get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY)->term_id);
    update_user_meta($id, 'sub_vehicle_1', 'AB-123-CD');
    sub_test_make_compliant($id);

    return $id;
};

$dp    = $makeDiver('p5');
$diver = $makeDiver('p3');

get_userdata($dp)->add_cap('sub_create_exploration_event');
get_userdata($dp)->add_cap('sub_create_governance_event');

// --- Champs selon le type -------------------------------------------------------
echo "\n--- Les champs suivent le type d’événement ---\n";

$diveType    = $service->typeBySlug('plongee-exploration');
$meetingType = $service->typeBySlug('reunion-bureau');

$diveFields    = RegistrationFields::forType($diveType);
$meetingFields = RegistrationFields::forType($meetingType);

$check('Une plongée pose des questions', count($diveFields) >= 3, count($diveFields) . ' champs');
$check('Une réunion n’en pose aucune', $meetingFields === [], count($meetingFields) . ' champs');
$check('La nature de la plongée est demandée', isset($diveFields['dive_intent']));
$check('Le pot est proposé', isset($diveFields['conviviality']));
$check('Le commentaire partagé est proposé', isset($diveFields['shared_note']));

// Matériel, transport, nombre de plongées : retirés à la demande du club, qui
// ne s'en servait pas. Le contrôle est là pour que personne ne les remette
// sans s'en apercevoir.
foreach (['tank', 'weights', 'driving', 'seats', 'vehicle', 'dive_count', 'nitrox', 'comment'] as $retire) {
    $check(sprintf('Champ « %s » retiré', $retire), !isset($diveFields[$retire]));
}

// --- Les options dépendent du niveau ------------------------------------------------
echo "\n--- Encadrer suppose un brevet ---\n";

$intent    = $diveFields['dive_intent'];
$forDiver  = RegistrationFields::optionsFor('dive_intent', $intent, $diver);
$forDp     = RegistrationFields::optionsFor('dive_intent', $intent, $dp);

$check('Un P3 ne peut pas s’inscrire comme encadrant',
    !isset($forDiver[RegistrationFields::INTENT_TEACHING]), implode(', ', array_keys($forDiver)));
$check('Ni choisir « indifférent »', !isset($forDiver[RegistrationFields::INTENT_ANY]));
$check('Un P5 a les quatre choix', count($forDp) === 4, implode(', ', array_keys($forDp)));

// Le P4 est guide de palanquée : il encadre en exploration sans porter de
// brevet d'enseignement. S'en tenir au drapeau « encadrant » l'exclurait.
$p4 = $makeDiver('p4');
$check('Un P4 peut encadrer, sans brevet E',
    isset(RegistrationFields::optionsFor('dive_intent', $intent, $p4)[RegistrationFields::INTENT_TEACHING]),
    'guide de palanquée');

$check('Les niveaux préparés viennent de la taxonomie',
    isset(RegistrationFields::optionsFor('training_level', $diveFields['training_level'], $diver)['p2']));

// --- Nettoyage des réponses ----------------------------------------------------------
echo "\n--- Ce qui entre en base ---\n";

$answers = RegistrationFields::sanitize([
    'dive_intent'         => RegistrationFields::INTENT_TRAINING,
    'training_level'      => 'p4',
    'previous_instructor' => 'Sophie',
    'conviviality'        => '1',
    'shared_note'         => 'Je fête mon N3.',
    // Champs inconnus ou non activés : ils ne doivent pas passer.
    'inconnu'             => 'valeur pirate',
    'capacity'            => '999',
], $diveType, $diver);

$check('Champ inconnu écarté', !isset($answers['inconnu']), implode(', ', array_keys($answers)));
$check('Réponses conservées', $answers['training_level'] === 'p4' && $answers['previous_instructor'] === 'Sophie');
$check('Case cochée normalisée', $answers['conviviality'] === '1');

$bad = RegistrationFields::sanitize(['dive_intent' => 'sous-marin-nucleaire'], $diveType, $diver);
$check('Choix hors liste refusé', !isset($bad['dive_intent']), 'valeur écartée');

// Le contrôle qui compte vraiment : la liste est filtrée à l'écran, mais rien
// n'empêche de poster la valeur à la main. C'est le serveur qui tranche.
$force = RegistrationFields::sanitize(
    ['dive_intent' => RegistrationFields::INTENT_TEACHING],
    $diveType,
    $diver
);
$check('Un P3 qui poste « encadrement » est refusé', !isset($force['dive_intent']),
    'le filtrage de la liste ne suffit pas');

// Un champ conditionnel dont la condition n'est pas remplie n'a rien à faire
// en base : sans cela, un « niveau préparé » saisi puis converti en
// exploration resterait attaché à l'inscription.
$switched = RegistrationFields::sanitize([
    'dive_intent'    => RegistrationFields::INTENT_EXPLORATION,
    'training_level' => 'p4',
], $diveType, $diver);
$check('Champ conditionnel écarté hors formation', !isset($switched['training_level']),
    implode(', ', array_keys($switched)));

// --- Inscription complète --------------------------------------------------------------
echo "\n--- Inscription avec réponses ---\n";

$eventId = $service->create('plongee-exploration', [
    'title'     => 'Sortie test formulaire',
    'location'  => 'Squewel',
    'starts_at' => gmdate('Y-m-d H:i:s', time() + 9 * 86400),
    'capacity'  => 10,
], $dp);

$service->register($eventId, $diver, $answers);

global $wpdb;
$stored = $wpdb->get_var($wpdb->prepare(
    "SELECT details FROM {$wpdb->prefix}sub_event_registrations WHERE event_id = %d AND user_id = %d",
    $eventId,
    $diver
));

$saved = (array) (json_decode((string) $stored, true) ?: []);
$check('Réponses enregistrées', $saved !== [], count($saved) . ' réponses');
$check('Niveau préparé conservé', ($saved['training_level'] ?? '') === 'p4');
$check('Commentaire partagé conservé', str_contains((string) ($saved['shared_note'] ?? ''), 'N3'));

// --- Ce que voit le directeur de plongée -----------------------------------------------
echo "\n--- Résumé pour le directeur de plongée ---\n";

$summary = RegistrationFields::summarise($saved, $diveType);
$joined  = implode(' | ', $summary);

$check('Résumé lisible produit', $summary !== [], $joined);
$check('La nature de la plongée est en clair', str_contains($joined, 'Formation'),
    'pas le code interne « formation »');
$check('Le niveau préparé est en clair', str_contains($joined, 'P4'), 'pas le slug « p4 »');
$check('Le moniteur précédent figure au résumé', str_contains($joined, 'Sophie'));

// --- Écrire au directeur de plongée ---------------------------------------------------
echo "\n--- Un inscrit écrit au directeur de plongée ---\n";

$envois = [];
add_filter('pre_wp_mail', static function ($null, array $atts) use (&$envois) {
    $envois[] = $atts;

    return true;
}, 10, 2);

$refuse = false;
try {
    // Le DP organise, il n'est pas inscrit : le canal doit lui être fermé aussi.
    $service->messageOrganizer($eventId, 'Test', $dp);
} catch (\RuntimeException) {
    $refuse = true;
}
$check('Un non-inscrit ne peut pas écrire', $refuse, 'sinon le formulaire devient un annuaire');

$sent = $service->messageOrganizer($eventId, 'À quelle heure la mise à l’eau ?', $diver);
$check('Un inscrit peut écrire', $sent);

$dernier = end($envois) ?: ['to' => '', 'headers' => [], 'message' => ''];
$entetes = implode(' ', (array) ($dernier['headers'] ?? []));

$check('Le message part vers le directeur de plongée',
    (string) $dernier['to'] === get_userdata($dp)->user_email);
$check('L’inscrit est en adresse de réponse',
    str_contains($entetes, 'Reply-To:') && str_contains($entetes, get_userdata($diver)->user_email),
    'répondre suffit, sans publier le courriel du DP');
$check('Le message est repris tel quel',
    str_contains((string) $dernier['message'], 'mise à l’eau'));

// --- Nettoyage -------------------------------------------------------------------------------
$wpdb->delete("{$wpdb->prefix}sub_event_registrations", ['event_id' => $eventId]);
$wpdb->delete("{$wpdb->prefix}sub_events", ['id' => $eventId]);

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$dp, $diver, $p4] as $id) {
    sub_test_clean_documents($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
