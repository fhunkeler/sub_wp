<?php
/**
 * Test de fumée de la suppression d'un compte.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-account-purge.php
 *
 * Le parcours testé ici n'est pas le parcours RGPD — celui-là a sa propre suite
 * (`smoke-rgpd`) et s'arrête devant une obligation en cours. C'est l'autre, le
 * plus courant : *Comptes → Supprimer*, l'écran natif de WordPress, qui efface
 * le compte sans rien connaître des tables du plugin.
 *
 * Trois choses à prouver : que la place réservée sur une sortie à venir repart
 * bien à la liste d'attente, que l'histoire du club survit sans plus désigner
 * personne, et qu'il ne reste **aucune ligne orpheline** — pas un fichier sur
 * le stockage, pas un identifiant qui pointe vers un compte disparu.
 */

require_once __DIR__ . '/helpers.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use Subalcatel\Club\Database\Schema;
use Subalcatel\Club\Documents\DocumentStorage;
use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Events\EventTypeSeeder;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Membership\ApplicationService;
use Subalcatel\Club\Membership\DemoSeeder;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Communication\CustomGroups;

global $wpdb;

// La migration fait partie du sujet : elle rend les colonnes détachables et
// solde les orphelins laissés par les suppressions d'avant.
Schema::migrate();
EventTypeSeeder::run();
EmailTemplates::seed();

$campaignId = DemoSeeder::run();
$failures   = 0;

$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-56s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

add_filter('pre_wp_mail', '__return_true');

$makeMember = static function (string $firstName): int {
    $id = wp_insert_user([
        'user_login' => 'purge_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => $firstName,
        'role'       => 'sub_member',
    ]);

    update_user_meta($id, 'sub_birth_date', '1985-06-02');
    update_user_meta($id, 'sub_mobile', '06 55 44 33 22');

    $level = get_term_by('slug', 'p3', DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $level->term_id);

    return $id;
};

// --- Mise en situation --------------------------------------------------------

$leaving  = $makeMember('Gwen');   // le compte qui sera supprimé
$waiting  = $makeMember('Soizic'); // premier de la liste d'attente
$organiser = $makeMember('Erwan');

foreach ([$leaving, $waiting, $organiser] as $id) {
    sub_test_make_compliant($id);
}

// Gwen encadre elle aussi : la sortie qu'elle a organisée doit survivre à son
// compte, et cesser de lui appartenir.
get_userdata($organiser)->add_cap('sub_create_exploration_event');
get_userdata($leaving)->add_cap('sub_create_exploration_event');

$events = new EventService();

// Une sortie à venir, une seule place : Gwen la prend, Soizic attend.
$upcoming = $events->create('plongee-exploration', [
    'title'           => 'Sortie de contrôle — suppression de compte',
    'starts_at'       => gmdate('Y-m-d H:i:s', time() + 21 * 86400),
    'location'        => 'Perros-Guirec',
    'capacity'        => 1,
    'accepted_levels' => ['p1', 'p3', 'p5'],
], $organiser);

$events->register($upcoming, $leaving, ['shared_note' => 'Je prends la voiture']);
$registered = $events->register($upcoming, $waiting);

$check('Départ : une place prise, une en attente', $registered['status'] === 'waiting');

// Une sortie déjà passée, où Gwen était. Créée à une date valide puis reculée :
// le service refuse — à raison — de créer une sortie dans le passé.
$past = $events->create('plongee-exploration', [
    'title'           => 'Sortie d’avant — suppression de compte',
    'starts_at'       => gmdate('Y-m-d H:i:s', time() + 3 * 86400),
    'location'        => 'Trégastel',
    'capacity'        => 8,
    'accepted_levels' => ['p1', 'p3', 'p5'],
], $leaving); // Gwen l'organisait : son compte disparaît, la sortie reste.

$events->register($past, $leaving, ['shared_note' => 'Covoiturage depuis Lannion']);
$events->register($past, $waiting);

$wpdb->update(
    "{$wpdb->prefix}sub_events",
    ['starts_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)],
    ['id' => $past]
);

// Un dossier d'adhésion réglé : pièce comptable, elle doit survivre.
$applications  = new ApplicationService();
$applicationId = $applications->submit($leaving, $campaignId, 'plongee', [
    'origine_adhesion'       => 'exterieur',
    'jeune'                  => 'non',
    'assurance_individuelle' => 'loisir1',
    'niveau_prepare'         => 'aucun',
    'carte_niveau'           => 'non',
    'pret_bloc'              => 'non',
    'pret_detendeur'         => 'non',
    'pret_gilet'             => 'non',
]);

$treasurer = wp_insert_user([
    'user_login' => 'purge_' . wp_generate_password(8, false),
    'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
    'user_pass'  => wp_generate_password(),
    'role'       => 'sub_office',
]);

$applications->recordPayment($applicationId, 210.00, 'cheque', current_time('Y-m-d'), $treasurer);

$reference = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT reference FROM {$wpdb->prefix}sub_applications WHERE id = %d",
    $applicationId
));

// Groupe de diffusion, historique de niveau, courriel reçu.
$groupId = CustomGroups::create('Contrôle suppression ' . wp_generate_password(4, false));
CustomGroups::setMembers($groupId, [$leaving, $waiting]);

$wpdb->insert("{$wpdb->prefix}sub_dive_level_history", [
    'user_id'       => $leaving,
    'level_term_id' => get_term_by('slug', 'p3', DiveLevels::TAXONOMY)->term_id,
    'obtained_on'   => '2019-05-01',
    'recorded_by'   => $treasurer,
]);

Mailer::toUser(EmailTemplates::DOCUMENT_VALIDATED, $leaving, ['document' => 'licence']);

// Relevés maintenant : après la suppression, plus rien ne reliera ces lignes au
// compte — c'est tout l'objet du test, et il faut bien pouvoir les retrouver.
$messageIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}sub_notification_log WHERE recipient_id = %d",
    $leaving
)) ?: []);

$filePath = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT file_path FROM {$wpdb->prefix}sub_member_documents WHERE user_id = %d LIMIT 1",
    $leaving
));

$check('Le fichier du document existe avant suppression', DocumentStorage::exists($filePath), $filePath);
$check('La sortie à venir affiche deux inscrits',
    count($events->socialParticipants($upcoming)) === 2);

// --- Suppression du compte, écran natif de WordPress ---------------------------
echo "\n--- Comptes → Supprimer ---\n";

wp_delete_user($leaving);

$check('Le compte a bien disparu', get_userdata($leaving) === false);

// --- La place réservée repart --------------------------------------------------
echo "\n--- La place rendue ---\n";

$upcomingRows = $wpdb->get_results($wpdb->prepare(
    "SELECT user_id, status FROM {$wpdb->prefix}sub_event_registrations WHERE event_id = %d",
    $upcoming
), ARRAY_A);

$check('L’inscription à venir est supprimée', count($upcomingRows) === 1,
    'une place réservée par personne bloque la sortie');

$promoted = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT status FROM {$wpdb->prefix}sub_event_registrations
     WHERE event_id = %d AND user_id = %d",
    $upcoming,
    $waiting
));

$check('Le premier de la liste d’attente est promu', $promoted === 'confirmed',
    'la place profite à quelqu’un d’autre');

$people = $events->socialParticipants($upcoming);

$check('La liste des participants n’a plus qu’un nom', count($people) === 1,
    count($people) . ' participant(s)');
$check('Et ce nom n’est pas vide',
    $people !== [] && trim($people[0]['name']) !== '' && $people[0]['name'] !== 'Membre du club');

// --- L'histoire du club reste --------------------------------------------------
echo "\n--- Ce que le club conserve ---\n";

$pastRow = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}sub_event_registrations
     WHERE event_id = %d AND user_id IS NULL",
    $past
), ARRAY_A);

$check('L’inscription passée est conservée', $pastRow !== null,
    'le nombre de participants d’une sortie appartient au club');
$check('Elle ne désigne plus personne', $pastRow !== null && $pastRow['user_id'] === null);
$check('Ses réponses individuelles sont effacées',
    $pastRow !== null && ($pastRow['details'] === null || $pastRow['details'] === ''));
$check('La sortie passée compte toujours deux inscrits',
    (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}sub_event_registrations
         WHERE event_id = %d AND status IN ('confirmed','waiting')",
        $past
    )) === 2);

$application = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}sub_applications WHERE id = %d",
    $applicationId
), ARRAY_A);

$check('Le dossier d’adhésion est conservé', $application !== null, $reference);
$check('Son montant et sa référence sont intacts',
    $application !== null && (float) $application['total_amount'] > 0
    && $application['reference'] === $reference,
    'une pièce comptable se conserve dix ans');
$check('Il ne désigne plus personne', $application !== null && $application['user_id'] === null);
$check('Le règlement suit le dossier', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_payments
     WHERE application_id = %d AND user_id IS NULL",
    $applicationId
)) === 1);

$check('La sortie organisée reste, sans organisateur',
    $wpdb->get_var($wpdb->prepare(
        "SELECT organizer_id FROM {$wpdb->prefix}sub_events WHERE id = %d",
        $past
    )) === null,
    'organizer_id ouvre le droit de modifier : il ne doit pas être réattribuable');

$check('Les courriels reçus sont toujours au journal',
    $messageIds !== [] && (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log
         WHERE id IN (" . implode(',', $messageIds) . ')'
    ) === count($messageIds));
$check('Mais sans identifiant ni adresse', $messageIds !== [] && (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log
     WHERE id IN (" . implode(',', $messageIds) . ")
       AND (recipient_id IS NOT NULL OR recipient_email <> '')"
) === 0);

// --- Ce qui devait partir ------------------------------------------------------
echo "\n--- Ce qui ne devait pas rester ---\n";

$check('Le fichier a disparu du stockage', !DocumentStorage::exists($filePath),
    'supprimer la ligne sans le fichier serait le pire des deux mondes');
$check('Plus aucun document en base', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_member_documents WHERE user_id = %d",
    $leaving
)) === 0);
$check('Plus aucun journal de consultation orphelin', (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_document_access_log l
     LEFT JOIN {$wpdb->prefix}sub_member_documents d ON d.id = l.document_id
     WHERE d.id IS NULL"
) === 0);
$check('Plus aucune appartenance à un groupe', !in_array($leaving, CustomGroups::members(
    (string) $wpdb->get_var($wpdb->prepare(
        "SELECT slug FROM {$wpdb->prefix}sub_mailing_groups WHERE id = %d",
        $groupId
    ))
), true));
$check('Plus aucun historique de niveau', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sub_dive_level_history WHERE user_id = %d",
    $leaving
)) === 0);

// --- L'invariant : aucune ligne ne pointe vers un compte disparu ---------------
echo "\n--- Aucune ligne orpheline, nulle part ---\n";

/**
 * Les colonnes qui désignent la *personne concernée* par une ligne.
 *
 * Absentes de cette liste, et volontairement : les colonnes d'acteur —
 * `recorded_by`, `verified_by`, `actor_id`, `sender_id`, et le journal d'audit
 * tout entier. Elles ne disent pas de qui parle la ligne, mais qui a agi. Les
 * vider effacerait la trace d'une décision, ce qu'un journal ne doit jamais
 * subir : c'est justement ce qui le rend opposable.
 */
$subjectColumns = [
    'sub_event_registrations'   => 'user_id',
    'sub_applications'          => 'user_id',
    'sub_payments'              => 'user_id',
    'sub_member_documents'      => 'user_id',
    'sub_mailing_group_members' => 'user_id',
    'sub_dive_level_history'    => 'user_id',
    'sub_notification_log'      => 'recipient_id',
    'sub_events'                => 'organizer_id',
];

foreach ($subjectColumns as $table => $column) {
    $orphans = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} t
         LEFT JOIN {$wpdb->users} u ON u.ID = t.`{$column}`
         WHERE t.`{$column}` IS NOT NULL AND t.`{$column}` <> 0 AND u.ID IS NULL"
    );

    $check(sprintf('%s.%s', $table, $column), $orphans === 0,
        $orphans === 0 ? '' : "{$orphans} ligne(s) sans compte");
}

// --- Nettoyage -----------------------------------------------------------------

// La suppression emporte documents, fichiers et inscriptions : c'est justement
// ce que cette suite vient de vérifier.
foreach ([$waiting, $organiser, $treasurer] as $id) {
    $wpdb->delete("{$wpdb->prefix}sub_notification_log", ['recipient_id' => $id]);
    wp_delete_user($id);
}

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}sub_event_registrations WHERE event_id IN (%d, %d)",
    $upcoming,
    $past
));
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}sub_events WHERE id IN (%d, %d)",
    $upcoming,
    $past
));
$wpdb->delete("{$wpdb->prefix}sub_application_lines", ['application_id' => $applicationId]);
$wpdb->delete("{$wpdb->prefix}sub_validations", ['application_id' => $applicationId]);
$wpdb->delete("{$wpdb->prefix}sub_payments", ['application_id' => $applicationId]);
$wpdb->delete("{$wpdb->prefix}sub_applications", ['id' => $applicationId]);
if ($messageIds !== []) {
    $wpdb->query(
        "DELETE FROM {$wpdb->prefix}sub_notification_log
         WHERE id IN (" . implode(',', $messageIds) . ')'
    );
}

CustomGroups::delete($groupId);

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
