<?php
/**
 * Test de fumée — les adhérents écrivent aux listes du club.
 *
 *   docker exec sub_demo_cli wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-list-messages.php
 *
 * [ListMessages] ouvre les listes de diffusion aux adhérents. Ce qui doit
 * tenir :
 *
 *   - un adhérent à jour écrit à tout le club, aux autonomes, aux encadrants ;
 *     un ancien adhérent ne le peut plus, ni personne à une liste réservée au
 *     bureau ;
 *   - le message arrive avec l'auteur en adresse de réponse, sans les adresses
 *     des autres, et chacun avec son propre lien de désabonnement ;
 *   - ceux qui ont refusé les communications, les anciens membres et l'auteur
 *     lui-même ne le reçoivent pas ; le représentant d'un mineur en a copie ;
 *   - le plafond quotidien par adhérent tient, le bureau y échappe.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Communication\ListMessages;
use Subalcatel\Club\Communication\MailingLists;
use Subalcatel\Club\Communication\Subscriptions;
use Subalcatel\Club\Frontend\ListMessageForm;
use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\Roles;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Setup\SiteMap;

DiveLevels::seed();
EmailTemplates::seed();

$failures = 0;
$check    = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-62s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$GLOBALS['sub_sent_mails'] = [];
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $GLOBALS['sub_sent_mails'][] = $atts;

    return true;
}, 10, 2);

$mailsTo = static function (string $email): array {
    return array_values(array_filter(
        $GLOBALS['sub_sent_mails'],
        static fn (array $mail): bool => in_array($email, (array) $mail['to'], true)
    ));
};

$today   = current_time('Y-m-d');
$created = [];

$member = static function (string $name, string $level, ?string $validUntil, string $role = Roles::MEMBER) use (&$created): int {
    $id = (int) wp_insert_user([
        'user_login'   => 'liste_' . wp_generate_password(8, false),
        'user_email'   => 'liste-' . strtolower(wp_generate_password(8, false)) . '@subalcatel.test',
        'user_pass'    => wp_generate_password(),
        'display_name' => $name,
        'first_name'   => $name,
        'role'         => $role,
    ]);

    $term = get_term_by('slug', $level, DiveLevels::TAXONOMY);
    update_user_meta($id, 'sub_dive_level_id', $term->term_id);

    if ($validUntil !== null) {
        update_user_meta($id, 'sub_membership_valid_until', $validUntil);
    }

    $created[] = $id;

    return $id;
};

$nextYear = gmdate('Y-m-d', strtotime($today . ' +1 year'));
$lastYear = gmdate('Y-m-d', strtotime($today . ' -1 year'));

$author   = $member('Anne Autonome', 'p3', $nextYear);
$buddy    = $member('Bruno Binôme', 'p4', $nextYear);
$moniteur = $member('Mona Monitrice', 'e3', $nextYear);
$debutant = $member('Denis Débutant', 'p1', $nextYear);
$refus    = $member('Rémi Refus', 'p3', $nextYear);
$ancien   = $member('Albert Ancien', 'p5', $lastYear);   // autonome, mais n'a pas renouvelé
$mineur   = $member('Noé Mineur', 'p3', $nextYear);
$office   = $member('Bérénice Bureau', 'p2', $nextYear, Roles::OFFICE);

update_user_meta($refus, Subscriptions::META_STATUS, 'no');
update_user_meta($mineur, 'sub_birth_date', gmdate('Y-m-d', strtotime('-16 years')));
update_user_meta($mineur, 'sub_guardian_name', 'Claire Mineur');
update_user_meta($mineur, 'sub_guardian_email', 'claire.mineur@subalcatel.test');

$email = static fn (int $id): string => get_userdata($id)->user_email;
$slugs = static fn (int $id): array => array_column(ListMessages::listsFor($id), 'slug');

// --- La liste des autonomes --------------------------------------------------
$autonomes = MailingLists::members(MailingLists::AUTONOMOUS);
$check('Liste « autonomes » : un P3 en fait partie', in_array($author, $autonomes, true));
$check('… un E3 aussi', in_array($moniteur, $autonomes, true));
$check('… un P1 non', !in_array($debutant, $autonomes, true));

// --- Qui peut écrire, et à quoi ----------------------------------------------
$check('Un adhérent à jour peut écrire', ListMessages::canSend($author));
$check('Un ancien adhérent ne le peut plus', !ListMessages::canSend($ancien));
$check('Un adhérent écrit à tout le club', in_array(MailingLists::ACTIVE, $slugs($author), true));
$check('… aux autonomes et aux encadrants', in_array(MailingLists::AUTONOMOUS, $slugs($author), true)
    && in_array(MailingLists::INSTRUCTOR, $slugs($author), true));
$check('… mais pas aux anciens adhérents', !in_array(MailingLists::FORMER, $slugs($author), true));
$check('Le bureau écrit aussi aux anciens adhérents', !user_can($office, 'sub_manage_content')
    || in_array(MailingLists::FORMER, $slugs($office), true));

$refused = '';
try {
    ListMessages::send(MailingLists::FORMER, 'Revenez', 'Texte', $author);
} catch (RuntimeException $e) {
    $refused = $e->getMessage();
}
$check('Liste réservée au bureau : envoi refusé', $refused !== '', $refused);

$refused = '';
try {
    ListMessages::send(MailingLists::ACTIVE, 'Bonjour', 'Texte', $ancien);
} catch (RuntimeException $e) {
    $refused = $e->getMessage();
}
$check('Ancien adhérent : envoi refusé', $refused !== '', $refused);

// --- Un message aux autonomes ------------------------------------------------
$recipients = ListMessages::recipients(MailingLists::AUTONOMOUS, $author);
$check('Destinataires : le binôme y est', in_array($buddy, $recipients, true));
$check('… pas l’auteur', !in_array($author, $recipients, true));
$check('… pas celui qui a refusé', !in_array($refus, $recipients, true));
$check('… pas l’ancien qui garde son niveau', !in_array($ancien, $recipients, true));

$GLOBALS['sub_sent_mails'] = [];
$result = ListMessages::send(
    MailingLists::AUTONOMOUS,
    'Plongée dimanche — qui vient ?',
    "Je cherche un binôme pour dimanche 9h.\nAnne",
    $author
);

$check('Tous les messages sont partis', $result['sent'] === $result['recipients'] && $result['queued'] === 0,
    sprintf('%d/%d', $result['sent'], $result['recipients']));

$toBuddy = $mailsTo($email($buddy));
$check('Le binôme reçoit un message', count($toBuddy) === 1);

$mail = $toBuddy[0] ?? ['to' => [], 'subject' => '', 'message' => '', 'headers' => []];
$check('Objet préfixé du club et de la liste', str_contains((string) $mail['subject'], 'Plongeurs autonomes')
    && str_contains((string) $mail['subject'], 'Plongée dimanche'), (string) $mail['subject']);
$check('Le texte de l’auteur y est', str_contains((string) $mail['message'], 'Je cherche un binôme'));
$check('Un seul destinataire par message', count((array) $mail['to']) === 1);
$check('Réponse à l’auteur', in_array(
    sprintf('Reply-To: Anne Autonome <%s>', $email($author)),
    (array) $mail['headers'],
    true
));
$check('Lien de désabonnement propre au destinataire',
    str_contains((string) $mail['message'], 'token=' . Subscriptions::token($buddy)));
$check('Aucune variable laissée en clair', !preg_match('/\{[a-z_]+\}/', (string) $mail['message']));

$check('L’auteur ne reçoit rien', $mailsTo($email($author)) === []);
$check('Celui qui a refusé ne reçoit rien', $mailsTo($email($refus)) === []);
$check('L’ancien ne reçoit rien', $mailsTo($email($ancien)) === []);
$check('Le P1 ne reçoit rien', $mailsTo($email($debutant)) === []);

$toGuardian = $mailsTo('claire.mineur@subalcatel.test');
$check('Le représentant du mineur en a copie', count($toGuardian) === 1);
$check('… avec le lien de désabonnement du mineur', isset($toGuardian[0])
    && str_contains((string) $toGuardian[0]['message'], 'token=' . Subscriptions::token($mineur)));

// --- Plafond quotidien -------------------------------------------------------
$check('Deux messages restent à l’auteur', ListMessages::remainingToday($author) === ListMessages::DAILY_LIMIT - 1);

ListMessages::send(MailingLists::INSTRUCTOR, 'Question', 'Texte', $author);
ListMessages::send(MailingLists::INSTRUCTOR, 'Question 2', 'Texte', $author);

$refused = '';
try {
    ListMessages::send(MailingLists::INSTRUCTOR, 'Question 3', 'Texte', $author);
} catch (RuntimeException $e) {
    $refused = $e->getMessage();
}
$check('Au-delà du plafond : refusé', $refused !== '', $refused);
$check('Le bureau n’a pas de plafond', !user_can($office, 'sub_manage_content')
    || ListMessages::remainingToday($office) === null);

// --- Message vide ------------------------------------------------------------
$refused = '';
try {
    ListMessages::send(MailingLists::ACTIVE, '  ', 'Texte', $buddy);
} catch (RuntimeException $e) {
    $refused = $e->getMessage();
}
$check('Objet vide : refusé', $refused !== '');
$check('… et le refus ne consomme pas le plafond', ListMessages::remainingToday($buddy) === ListMessages::DAILY_LIMIT);

// --- La page -----------------------------------------------------------------
$keys = array_column(SiteMap::pages(), 'key');
$check('La page figure au plan du site', in_array(Pages::WRITE_TO_CLUB, $keys, true));

wp_set_current_user($buddy);
$html = ListMessageForm::render();
$check('Le formulaire propose « Tout le club »', str_contains($html, 'value="' . MailingLists::ACTIVE . '"'));
$check('… avec le nombre de destinataires', (bool) preg_match('/\d+ destinataires?/', $html));
$check('… et sans aucune adresse', !str_contains($html, '@subalcatel.test'));
$check('Un adhérent ne voit pas les noms des destinataires',
    !str_contains($html, 'sub-list-message__recipients') && !str_contains($html, 'Mona Monitrice'));

// Le bureau, lui, voit qui recevra le message avant de l'envoyer.
wp_set_current_user($office);
$html = ListMessageForm::render();
$officeSees = ListMessages::canSeeRecipients($office);
$check('Le bureau voit la liste des destinataires', !$officeSees
    || (str_contains($html, 'Voir les ') && str_contains($html, 'Mona Monitrice')));
$check('… sans leurs adresses', !str_contains($html, '@subalcatel.test'));
$check('… ni ceux qui ont refusé les communications', !str_contains($html, 'Rémi Refus'));
$check('… ni lui-même', !str_contains($html, '<li>Bérénice Bureau</li>'));

$names = ListMessages::recipientNames([$moniteur, $buddy, $author]);
$check('Les noms sont triés sans tenir compte des accents', $names === [
    'Anne Autonome', 'Bruno Binôme', 'Mona Monitrice',
], implode(', ', $names));
$check('Un membre simple n’y a pas droit', !ListMessages::canSeeRecipients($buddy));

wp_set_current_user($ancien);
$check('Un ancien adhérent voit une porte fermée', !str_contains(ListMessageForm::render(), 'name="liste"'));
wp_set_current_user(0);

// --- Nettoyage ---------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ($created as $id) {
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
