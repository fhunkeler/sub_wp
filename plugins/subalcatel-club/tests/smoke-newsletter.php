<?php
/**
 * Test de fumée des listes de diffusion.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-newsletter.php
 *
 * Le point à prouver : **être adhérent ne vaut pas consentement**. Une liste
 * dit qui appartient au groupe ; l'abonnement dit à qui on a le droit d'écrire.
 * Confondre les deux, c'est écrire à des gens qui s'étaient désinscrits.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Communication\CustomGroups;
use Subalcatel\Club\Communication\MailingLists;
use Subalcatel\Club\Communication\Subscriptions;
use Subalcatel\Club\Exports\ExportRegistry;
use Subalcatel\Club\Identity\DiveLevels;

global $wpdb;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

$makeUser = static function (string $role, ?string $levelSlug, ?string $validUntil): int {
    $id = wp_insert_user([
        'user_login' => 'demo_' . wp_generate_password(8, false),
        'user_email' => wp_generate_password(8, false) . '@subalcatel.test',
        'user_pass'  => wp_generate_password(),
        'first_name' => 'Test',
        'role'       => $role,
    ]);

    if ($levelSlug !== null) {
        $term = get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY);
        update_user_meta($id, 'sub_dive_level_id', $term->term_id);
    }

    if ($validUntil !== null) {
        update_user_meta($id, 'sub_membership_valid_until', $validUntil);
    }

    return $id;
};

$active   = $makeUser('sub_member', 'p3', '2027-12-31');
$former   = $makeUser('sub_member', 'p1', gmdate('Y-m-d', strtotime('-6 months')));
$ancient  = $makeUser('sub_member', 'p1', gmdate('Y-m-d', strtotime('-5 years')));
$leader   = $makeUser('sub_member', 'p5', '2027-12-31');
$office   = $makeUser('sub_office', 'e3', '2027-12-31');

// --- Composition des listes --------------------------------------------------
echo "\n--- Listes calculées ---\n";

$check('Adhérent actif dans « adhérents actifs »',
    in_array($active, MailingLists::members(MailingLists::ACTIVE), true));
$check('Adhésion expirée exclue des actifs',
    !in_array($former, MailingLists::members(MailingLists::ACTIVE), true));

$formerList = MailingLists::members(MailingLists::FORMER);
$check('Expiré depuis 6 mois : ancien adhérent', in_array($former, $formerList, true));
$check('Expiré depuis 5 ans : sorti des listes', !in_array($ancient, $formerList, true),
    'au-delà de deux ans, on n’écrit plus');
$check('Un actif n’est pas un ancien', !in_array($active, $formerList, true));

$check('Le bureau est détecté par sa capacité',
    in_array($office, MailingLists::members(MailingLists::OFFICE), true));
$check('Un adhérent ordinaire n’est pas au bureau',
    !in_array($active, MailingLists::members(MailingLists::OFFICE), true));

$check('Directeur de plongée reconnu par son niveau',
    in_array($leader, MailingLists::members(MailingLists::LEADER), true), 'P5');
$check('Un P3 n’est pas directeur de plongée',
    !in_array($active, MailingLists::members(MailingLists::LEADER), true));
$check('Encadrant reconnu par son niveau',
    in_array($office, MailingLists::members(MailingLists::INSTRUCTOR), true), 'E3');

// --- Une liste par niveau ----------------------------------------------------
echo "\n--- Listes par niveau, générées ---\n";

$slugs  = array_column(MailingLists::all(), 'slug');
$levels = array_filter($slugs, static fn (string $s): bool => str_starts_with($s, MailingLists::LEVEL_PREFIX));

$check('Une liste par niveau existe', count($levels) >= 20, count($levels) . ' listes');
$check('La liste du P3 contient le P3',
    in_array($active, MailingLists::members(MailingLists::LEVEL_PREFIX . 'p3'), true));
$check('Elle ne contient pas le P5',
    !in_array($leader, MailingLists::members(MailingLists::LEVEL_PREFIX . 'p3'), true));

// Ajouter un niveau doit créer sa liste sans une ligne de code.
$newLevel = wp_insert_term('Niveau de contrôle', DiveLevels::TAXONOMY, ['slug' => 'niveau-controle']);
$after    = array_column(MailingLists::all(), 'slug');

$check('Un niveau ajouté crée sa liste',
    in_array(MailingLists::LEVEL_PREFIX . 'niveau-controle', $after, true),
    'aucun développement nécessaire');

wp_delete_term($newLevel['term_id'], DiveLevels::TAXONOMY);

// --- Consentement ------------------------------------------------------------
echo "\n--- Consentement ---\n";

// Les contrôles portent sur les comptes créés ici, jamais sur des totaux : la
// base de démonstration contient de vrais adhérents, et un test qui compte tout
// le monde échoue au premier membre ajouté.
$fixtures = [$active, $former, $ancient, $leader, $office];
$emailsOf = static fn (array $ids): array
    => array_map(static fn (int $id): string => get_userdata($id)->user_email, $ids);

$check('Aucun consentement par défaut', !Subscriptions::isSubscribed($active),
    'l’absence de réponse vaut refus');

$subscribedFixtures = array_filter($fixtures, [Subscriptions::class, 'isSubscribed']);
$check('Effectif et abonnés diffèrent', $subscribedFixtures === [],
    '5 membres, 0 abonné');

$reached = array_intersect(
    $emailsOf($fixtures),
    array_column(MailingLists::recipients(MailingLists::ACTIVE), 'email')
);
$check('Aucun destinataire tant que personne n’a consenti', $reached === []);

Subscriptions::subscribe($active);
Subscriptions::subscribe($leader);

$check('Abonné après consentement', Subscriptions::isSubscribed($active));
$check('Date de consentement enregistrée',
    Subscriptions::stateOf($active)['date'] === current_time('Y-m-d'));

$recipients = MailingLists::recipients(MailingLists::ACTIVE);
$emails     = array_column($recipients, 'email');

$check('Le consentant est destinataire',
    in_array(get_userdata($active)->user_email, $emails, true), count($recipients) . ' destinataire(s)');
$check('Le non-consentant est exclu',
    !in_array(get_userdata($office)->user_email, $emails, true),
    'membre de la liste, mais pas abonné');

// --- Reprise depuis AcyMailing ------------------------------------------------
echo "\n--- Reprise d’un abonnement existant ---\n";

Subscriptions::subscribe($office, 'acymailing', '2019-06-14');
$state = Subscriptions::stateOf($office);

$check('Date d’origine conservée', $state['date'] === '2019-06-14',
    'la réécrire effacerait la preuve du consentement');
$check('Origine tracée', $state['source'] === 'acymailing');

// --- Désabonnement -----------------------------------------------------------
echo "\n--- Désabonnement ---\n";

$url = Subscriptions::unsubscribeUrl($leader);
$check('Le lien porte un jeton', str_contains($url, 'token='));
$check('Le jeton est propre au compte',
    Subscriptions::token($leader) !== Subscriptions::token($active));
$check('Le jeton est stable', Subscriptions::token($leader) === Subscriptions::token($leader),
    'sinon un lien envoyé hier ne marcherait plus');

Subscriptions::unsubscribe($leader, 'lien');

$check('Désabonné', !Subscriptions::isSubscribed($leader));
$check('Retiré des destinataires',
    !in_array(get_userdata($leader)->user_email, array_column(MailingLists::recipients(MailingLists::ACTIVE), 'email'), true));
$check('Toujours membre de la liste',
    in_array($leader, MailingLists::members(MailingLists::ACTIVE), true),
    'se désabonner n’est pas quitter le club');

// --- Groupes constitués à la main --------------------------------------------
echo "\n--- Groupes du bureau ---\n";

$groupId = CustomGroups::create('Commission bio', 'Membres de la commission biologie.');
CustomGroups::setMembers($groupId, [$active, $leader]);

// Le slug est lu, jamais deviné : un groupe du même nom peut déjà exister et
// `create()` suffixe alors le sien.
global $wpdb;
$slug = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT slug FROM {$wpdb->prefix}sub_mailing_groups WHERE id = %d",
    $groupId
));
$listSlug = MailingLists::GROUP_PREFIX . $slug;

$check('Groupe créé', str_starts_with($slug, 'commission-bio'), $slug);
$check('Il apparaît dans les listes',
    in_array($listSlug, array_column(MailingLists::all(), 'slug'), true));
$check('Composition enregistrée', count(MailingLists::members($listSlug)) === 2);
$check('Marqué comme non dynamique', MailingLists::find($listSlug)['dynamic'] === false);

CustomGroups::setMembers($groupId, [$active]);
$check('La composition se remplace en bloc', MailingLists::members($listSlug) === [$active]);

// --- Export ------------------------------------------------------------------
echo "\n--- Export des destinataires ---\n";

$export = ExportRegistry::find('mailing-list');

$check('Export déclaré au registre', $export !== null);
$check('Réservé au bureau', $export->capability() === 'sub_manage_content');

$rows = $export->rows(['list' => MailingLists::ACTIVE]);

// Parmi les comptes de ce test : celui qui a coché la case et celui repris
// d'AcyMailing sortent ; le désabonné et le silencieux non.
$exported = array_intersect($emailsOf($fixtures), array_column($rows, 0));

$check('Le consentant et le repris sortent', count($exported) === 2, implode(', ', $exported));
$check('Le désabonné ne sort pas',
    !in_array(get_userdata($leader)->user_email, $exported, true));
$check('Le silencieux ne sort pas',
    !in_array(get_userdata($former)->user_email, $exported, true),
    'jamais coché la case');

$dates = array_column($rows, 4);

$check('La date de consentement accompagne chaque ligne',
    in_array(current_time('Y-m-d'), $dates, true), 'elle rend l’envoi défendable');
$check('La date reprise d’AcyMailing survit à l’export',
    in_array('2019-06-14', $dates, true), 'et non la date de l’import');
$check('Liste inconnue : export vide', $export->rows(['list' => 'inexistante']) === []);

// --- Nettoyage ---------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/user.php';

CustomGroups::delete($groupId);

foreach ([$active, $former, $ancient, $leader, $office] as $id) {
    CustomGroups::forgetUser($id);
    wp_delete_user($id);
}

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
