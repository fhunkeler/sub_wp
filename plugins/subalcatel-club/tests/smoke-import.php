<?php
/**
 * Test de fumée — reprise des données Joomla.
 *
 *   docker exec sub_demo_wp wp --allow-root eval-file \
 *     wp-content/plugins/subalcatel-club/tests/smoke-import.php
 *
 * Ce que ce test garde, et qui compte plus que le nombre de lignes reprises :
 * la reprise ne fait **jamais** entrer dans le nouveau site ce qui a rendu
 * l'ancien vulnérable. Aucun mot de passe hérité, aucun script dans le contenu,
 * aucune écriture possible vers la base d'origine, et une reprise rejouable
 * sans créer de doublon.
 */

require_once __DIR__ . '/helpers.php';

use Subalcatel\Club\Import\LegacyMedia;
use Subalcatel\Club\Import\LegacySource;
use Subalcatel\Club\Import\Report;
use Subalcatel\Club\Import\Sanitizer;
use Subalcatel\Club\Import\UserImporter;

$failures = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    printf("%s  %-58s %s\n", $ok ? ' OK ' : 'FAIL', $label, $note !== '' ? "→ {$note}" : '');
};

// --- La base héritée est inaccessible en écriture ----------------------------
echo "\n--- Étanchéité de la source héritée ---\n";

// Identifiants pris dans l'environnement, jamais écrits ici : ce fichier est
// versionné, et un mot de passe commité reste dans l'historique même effacé.
//
//   SUB_LEGACY_PASS=… docker exec -e SUB_LEGACY_PASS sub_demo_wp wp …
$source = new LegacySource(
    getenv('SUB_LEGACY_BASE') ?: 'joomla_legacy',
    getenv('SUB_LEGACY_USER') ?: 'root',
    getenv('SUB_LEGACY_PASS') ?: '',
    getenv('SUB_LEGACY_HOTE') ?: 'db',
    'jml_'
);

$check('La base héritée est joignable', $source->isReady());

foreach ([
    'DELETE FROM jml_users',
    'UPDATE jml_users SET block = 1',
    'DROP TABLE jml_users',
    'INSERT INTO jml_users (id) VALUES (1)',
] as $forbidden) {
    $refused = false;
    try {
        $source->rows($forbidden);
    } catch (LogicException) {
        $refused = true;
    }
    $check(sprintf('Refuse « %s »', substr($forbidden, 0, 22) . '…'), $refused,
        'la source est une pièce d’archive, pas une base de travail');
}

// --- Le nettoyage HTML ne laisse rien d'exécutable ---------------------------
echo "\n--- Assainissement du contenu ---\n";

$attaques = [
    'script'          => '<p>Bonjour</p><script>alert(1)</script>',
    'gestionnaire on' => '<img src="x" onerror="alert(1)">',
    'protocole js'    => '<a href="javascript:alert(1)">clic</a>',
    'iframe'          => '<iframe src="http://mechant.test"></iframe>',
    'php'             => '<p>Salut</p><?php system($_GET["c"]); ?>',
    'formulaire'      => '<form action="http://mechant.test"><input name="p"></form>',
    'objet'           => '<object data="x.swf"></object>',
];

foreach ($attaques as $nom => $charge) {
    $propre = Sanitizer::html($charge)['html'];
    $sale   = (bool) preg_match('/<\s*(script|iframe|object|embed|form)\b|javascript\s*:|\son[a-z]+\s*=|<\?php/i', $propre);
    $check(sprintf('Neutralise : %s', $nom), !$sale, $sale ? "reste : {$propre}" : '');
}

$check('Conserve le texte légitime',
    str_contains(Sanitizer::html('<p>Bonjour</p><script>alert(1)</script>')['html'], 'Bonjour'),
    'nettoyer n’est pas vider');
$check('Signale ce qu’il a retiré',
    in_array('script', Sanitizer::html('<script>x</script>')['removed'], true),
    'une reprise muette est invérifiable');

// --- Les dates Joomla douteuses ne deviennent pas des dates fausses ----------
echo "\n--- Robustesse du nettoyage des champs ---\n";

$check('Rejette la date zéro de Joomla', Sanitizer::date('0000-00-00 00:00:00') === null);
$check('Rejette une date vide', Sanitizer::date('') === null);
$check('Accepte le format ISO', Sanitizer::date('2025-09-30 21:59:59') === '2025-09-30');
$check('Accepte le format français', Sanitizer::date('30/09/2025') === '2025-09-30');
$check('Rejette une année aberrante', Sanitizer::date('1850-01-01') === null,
    'une naissance en 1850 est une saisie erronée, pas une donnée');
$check('Rejette une adresse e-mail invalide', Sanitizer::email('pas-une-adresse') === '');
$check('Normalise la casse de l’e-mail', Sanitizer::email('Jean.Dupont@Example.COM') === 'jean.dupont@example.com');
$check('Nettoie un téléphone', Sanitizer::phone('06 12<script> 34') === '06 12 34');
$check('Remplace un identifiant vidé par le nettoyage',
    Sanitizer::login('!!!', 'membre42') === 'membre42',
    'jamais d’identifiant vide');

// --- Les images reprises sont réécrites, pas seulement vérifiées -------------
echo "\n--- Reprise des images ---\n";

$transit = sys_get_temp_dir() . '/sub-test-transit-' . wp_generate_password(8, false);
mkdir($transit, 0o755, true);

// Une vraie image PNG, à laquelle on accole une charge PHP : c'est un
// polyglotte, la forme d'attaque que le dossier images/ du Joomla hébergeait.
$gd = imagecreatetruecolor(24, 24);
imagefill($gd, 0, 0, imagecolorallocate($gd, 10, 90, 160));
ob_start();
imagepng($gd);
$pngPropre = (string) ob_get_clean();
imagedestroy($gd);

$polyglotte = $pngPropre . '<?php system($_GET["c"]); ?>';
file_put_contents($transit . '/000.png', $polyglotte);
file_put_contents($transit . '/index.tsv', "000.png\timages/photo.png\n");

$rapport = new Report();
$medias  = new LegacyMedia($rapport, $transit);
$rendu   = $medias->rewrite('<p><img src="images/photo.png" alt=""></p>', 'Test', false);

$check('Une image polyglotte est bien reprise', $rendu['imported'] === 1,
    'le fichier est une image valide : on le reprend');

$fichier = '';
if (preg_match('#src="([^"]+)"#', $rendu['html'], $m)) {
    $fichier = str_replace(
        wp_get_upload_dir()['baseurl'],
        wp_get_upload_dir()['basedir'],
        $m[1]
    );
}

$stocke = is_readable($fichier) ? (string) file_get_contents($fichier) : '';

$check('Le fichier déposé existe', $stocke !== '');
$check('La charge PHP n’a pas survécu au ré-encodage',
    $stocke !== '' && !str_contains($stocke, '<?php'),
    'GD relit les pixels et réécrit : ce qui n’est pas de l’image ne passe pas');
$check('Le résultat reste une image valide',
    $stocke !== '' && @getimagesizefromstring($stocke) !== false);

// Évasion de répertoire : un `src` est du contenu venant d'un site compromis.
file_put_contents($transit . '/index.tsv', "../../../etc/passwd\timages/evasion.png\n");
$rapportEvasion = new Report();
$evasion = (new LegacyMedia($rapportEvasion, $transit))
    ->rewrite('<img src="images/evasion.png">', 'Test', true);

$check('Un chemin sortant de la zone de transit est refusé',
    $evasion['imported'] === 0 && $evasion['missing'] === 1,
    'realpath + comparaison de préfixe, seule vérification qui résiste aux « .. »');

// Une balise dont l'image manque doit disparaître, pas rester cassée.
$check('La balise d’une image absente est retirée',
    !str_contains($evasion['html'], '<img'),
    'un carré cassé n’informe personne');

// La balise doit partir ENTIÈRE. Ne retirer que le `src` laissait la queue
// de la balise s'afficher en clair : « alt="permanence2022" /> » est
// réellement apparu sur la page des actualités.
$rapportQueue = new Report();
$queue = (new LegacyMedia($rapportQueue, $transit))->rewrite(
    '<p>Le planning.</p><p><img src="introuvable.png" alt="planning" style="margin: 0px;" /></p>',
    'Test',
    true
);

$check('Aucun attribut orphelin après retrait',
    !str_contains($queue['html'], 'alt=') && !str_contains($queue['html'], '/>'),
    'la balise entière part, pas seulement son src');
$check('Le texte voisin est préservé',
    str_contains($queue['html'], 'Le planning.'),
    'retirer une image ne doit pas amputer l’article');

array_map('unlink', glob($transit . '/*') ?: []);
@rmdir($transit);

// --- Le choix de la vignette d'un article repris ------------------------------
echo "\n--- Vignette des cartes d'actualité ---\n";

// Joomla n'avait pas d'image mise en avant : elle est déduite du corps de
// l'article. Ce qui compte n'est pas qu'une vignette soit posée, c'est qu'elle
// soit regardable une fois étirée à la largeur d'une carte.
$faireMedia = static function (int $largeur, int $hauteur) use (&$mediasTest): int {
    $id = wp_insert_post([
        'post_type'   => 'attachment',
        'post_title'  => sprintf('test-%dx%d', $largeur, $hauteur),
        'post_status' => 'inherit',
    ]);

    wp_update_attachment_metadata($id, ['width' => $largeur, 'height' => $hauteur]);
    $mediasTest[] = $id;

    return (int) $id;
};

$mediasTest = [];
$balise     = static fn (int $id): string => sprintf('<img src="x.jpg" class="wp-image-%d" />', $id);

$icone     = $faireMedia(120, 90);     // pastille « félicitations » d'ouverture
$photo     = $faireMedia(1440, 1080);  // la vraie photo de l'article
$bandeau   = $faireMedia(553, 49);     // filet de pied de page
$heritee   = $faireMedia(240, 180);    // vignette héritée d'une fiche de site
$heritee2  = $faireMedia(240, 161);    // une seconde, plus loin dans l'article

$thumbnails = new \Subalcatel\Club\Import\ArticleThumbnails();

$check('Passe la pastille d’ouverture pour la photo',
    $thumbnails->choose($balise($icone) . '<p>Bravo !</p>' . $balise($photo)) === $photo,
    'une icône de 120 px étirée sur une carte ne dit rien de l’article');

$check('Écarte un bandeau trop plat',
    $thumbnails->choose($balise($bandeau)) === 0,
    'recadré au 3/2, il ne reste qu’une bande illisible');

$check('Accepte une vignette héritée à défaut de mieux',
    $thumbnails->choose($balise($heritee)) === $heritee,
    'c’est la seule illustration des fiches de sites de plongée');

$check('À qualité égale, l’ordre de rédaction l’emporte',
    $thumbnails->choose($balise($heritee2) . $balise($heritee)) === $heritee2,
    'la première image est le sujet, la suivante souvent un rappel');

$check('Une photo pleine taille bat une vignette qui la précède',
    $thumbnails->choose($balise($heritee) . $balise($photo)) === $photo,
    'au-delà de la largeur de carte, on sert sans étirement');

$check('Aucune image exploitable donne 0',
    $thumbnails->choose('<p>Un article sans photo.</p>') === 0,
    'le thème pose alors son repli — il ne faut pas lui forcer la main');

$check('Une image hors médiathèque est ignorée',
    $thumbnails->choose('<img src="http://ailleurs.test/photo.jpg" />') === 0,
    'sans wp-image-{id}, aucune dimension connue');

foreach ($mediasTest as $id) {
    wp_delete_post($id, true);
}

// --- Le contrat de username_exists() -----------------------------------------
echo "\n--- Collision d’identifiant de connexion ---\n";

// Ce contrôle existe pour une raison précise : `username_exists()` renvoie
// `false` quand l’identifiant est libre, jamais `null`. Une boucle qui teste
// `!== null` tourne donc à l’infini. Le piège a coûté un import bloqué ; il ne
// doit pas revenir.
$check('username_exists() ne renvoie jamais null pour un identifiant libre',
    username_exists('identifiant-assurement-libre-' . wp_generate_password(12, false)) !== null,
    'c’est false, pas null — une boucle « !== null » ne se termine jamais');
$check('username_exists() est faux pour un identifiant libre',
    !username_exists('identifiant-assurement-libre-' . wp_generate_password(12, false)),
    'le test doit porter sur la véracité');

// --- Aucun mot de passe hérité ------------------------------------------------
echo "\n--- Les mots de passe ne sont pas repris ---\n";

global $wpdb;

$importes = get_users([
    'meta_key' => UserImporter::JOOMLA_ID_META,
    'fields'   => ['ID'],
    'number'   => 200,
]);

if ($importes === []) {
    echo "  (aucun compte repris en base : contrôle des condensats ignoré)\n";
} else {
    $ids = implode(',', array_map(static fn ($u): int => (int) $u->ID, $importes));

    // Les condensats Joomla commencent par $2y$ (bcrypt nu). WordPress préfixe
    // les siens ($P$ historique, $wp$2y$ moderne). Un $2y$ nu signifierait
    // qu'un condensat hérité est passé tel quel.
    $herites = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID IN ({$ids}) AND user_pass LIKE '\$2y\$%'"
    );
    $check('Aucun condensat Joomla nu en base', $herites === 0,
        'le site source était compromis : ses mots de passe sont à considérer comme connus');

    $vides = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID IN ({$ids}) AND (user_pass = '' OR user_pass IS NULL)"
    );
    $check('Aucun compte sans mot de passe', $vides === 0,
        'un condensat vide ouvrirait le compte');

    $check('Les comptes repris portent leur marque d’origine', count($importes) > 0,
        count($importes) . ' compte(s) tracé(s)');

    // --- Pas de doublon -------------------------------------------------------
    echo "\n--- Reprise rejouable ---\n";

    $doublons = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM (
            SELECT meta_value FROM {$wpdb->usermeta}
            WHERE meta_key = '" . UserImporter::JOOMLA_ID_META . "'
            GROUP BY meta_value HAVING COUNT(*) > 1
         ) x"
    );
    $check('Aucun compte Joomla repris deux fois', $doublons === 0);

    $emailsDoubles = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM (
            SELECT user_email FROM {$wpdb->users} GROUP BY user_email HAVING COUNT(*) > 1
         ) x"
    );
    $check('Aucune adresse e-mail en double', $emailsDoubles === 0);
}

// --- Les rôles hérités ne donnent pas les pleins pouvoirs --------------------
echo "\n--- Privilèges ---\n";

$admins = get_users([
    'meta_key' => UserImporter::JOOMLA_ID_META,
    'role'     => 'administrator',
    'fields'   => ['ID'],
]);
$check('Aucun compte repris n’est administrateur', $admins === [],
    '14 comptes cumulaient ce privilège sur l’ancien site ; ils passent en bureau');

printf("\n%s\n", $failures === 0 ? '✓ Tous les contrôles passent.' : "✗ {$failures} échec(s).");
exit($failures === 0 ? 0 : 1);
