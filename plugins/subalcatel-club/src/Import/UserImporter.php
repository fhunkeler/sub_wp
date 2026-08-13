<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\Roles;

/**
 * Reprise des comptes Joomla.
 *
 * ## Les mots de passe ne sont jamais repris
 *
 * C'est la décision structurante de cette reprise, et elle n'est pas
 * négociable : le site source a été compromis. On ignore ce que l'attaquant a
 * pu lire, et une base de condensats volée se casse hors ligne, tranquillement,
 * pendant des mois. Reprendre les mots de passe reviendrait à faire entrer dans
 * le nouveau site des identifiants qu'il faut désormais considérer comme
 * connus de tiers.
 *
 * Chaque compte importé reçoit donc un mot de passe aléatoire que personne ne
 * connaît — pas même l'importeur, qui ne le journalise pas — et l'adhérent
 * passe par « mot de passe oublié » à sa première venue. Le désagrément est
 * d'une minute, une fois. C'est le prix d'une reprise qui ne réimporte pas la
 * compromission avec les données.
 */
final class UserImporter
{
    /** Marque le compte d'origine : rend la reprise rejouable sans doublon. */
    public const JOOMLA_ID_META = '_sub_joomla_user_id';

    /**
     * Compte qui *serait* créé, en simulation.
     *
     * Sans ce jeton, la simulation des adhésions écarterait les 86 lignes faute
     * de compte associé, et n'apprendrait donc rien — une simulation à moitié
     * aveugle donne une fausse assurance, ce qui est pire que pas de simulation.
     */
    public const PENDING = -1;

    /** Groupes Joomla emportant une responsabilité. */
    private const GROUP_MANAGER    = 6;
    private const GROUP_ADMIN      = 7;
    private const GROUP_SUPER      = 8;
    private const GROUP_COMMITTEE  = 10;
    private const GROUP_MEMBER     = 11;
    private const GROUP_DIVE_LEAD  = 12;

    /** Groupes qui justifient de garder un compte même sans adhésion à jour. */
    private const RESPONSIBILITY = [
        self::GROUP_MANAGER,
        self::GROUP_ADMIN,
        self::GROUP_SUPER,
        self::GROUP_COMMITTEE,
        self::GROUP_DIVE_LEAD,
    ];

    public function __construct(
        private readonly LegacySource $source,
        private readonly Report $report
    ) {
    }

    /**
     * Comptes retenus par la règle « actif au sens large ».
     *
     * Trois motifs de reprise, cumulables : une adhésion en cours, une
     * responsabilité dans le club, ou une connexion récente. Un compte sans
     * aucun des trois est un reliquat — la migration est le bon moment pour
     * ne pas l'emporter.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(string $activeSince = '2024-01-01'): array
    {
        $users       = $this->source->table('users');
        $map         = $this->source->table('user_usergroup_map');
        $subscribers = $this->source->table('osmembership_subscribers');
        $profiles    = $this->source->table('comprofiler');
        $groups      = implode(',', self::RESPONSIBILITY);

        return $this->source->rows(
            "SELECT u.*, c.*, u.id AS joomla_id,
                    EXISTS(SELECT 1 FROM {$subscribers} s
                           WHERE s.user_id = u.id AND s.published = 1) AS has_active,
                    EXISTS(SELECT 1 FROM {$map} m
                           WHERE m.user_id = u.id AND m.group_id IN ({$groups})) AS has_duty
             FROM {$users} u
             LEFT JOIN {$profiles} c ON c.user_id = u.id
             WHERE EXISTS(SELECT 1 FROM {$subscribers} s
                          WHERE s.user_id = u.id AND s.published = 1)
                OR EXISTS(SELECT 1 FROM {$map} m
                          WHERE m.user_id = u.id AND m.group_id IN ({$groups}))
                OR u.lastvisitDate >= %s
             ORDER BY u.id",
            [$activeSince]
        );
    }

    /**
     * Importe les comptes. En simulation, rien n'est écrit.
     *
     * @return array<int, int> identifiant Joomla => identifiant WordPress
     */
    public function run(bool $dryRun = true, string $activeSince = '2024-01-01'): array
    {
        $mapping = [];

        foreach ($this->candidates($activeSince) as $row) {
            $joomlaId = (int) $row['joomla_id'];
            $email    = Sanitizer::email($row['email'] ?? null);

            if ($email === '') {
                $this->report->skip('comptes', $joomlaId, 'adresse e-mail invalide');
                continue;
            }

            $userId = $this->resolveExisting($joomlaId, $email);

            if ($userId > 0) {
                $this->report->skip('comptes', $joomlaId, 'déjà importé (compte ' . $userId . ')');
                $mapping[$joomlaId] = $userId;
                continue;
            }

            if ($dryRun) {
                $mapping[$joomlaId] = self::PENDING;
                // Contrôlé dès la simulation : un niveau fédéral non reconnu
                // doit se voir avant l'écriture, pas après.
                $this->resolveDiveLevel((string) ($row['cb_niveauplongee'] ?? ''), $joomlaId, 'compte Joomla');
                $this->report->add('comptes', $joomlaId, $this->describe($row, $email));
                continue;
            }

            $userId = $this->create($row, $email);

            if ($userId === 0) {
                continue;
            }

            $mapping[$joomlaId] = $userId;
            $this->report->add('comptes', $joomlaId, $this->describe($row, $email));
        }

        return $mapping;
    }

    /**
     * Retrouve un compte déjà repris, par marque d'origine puis par e-mail.
     *
     * L'e-mail sert de second filet : si le bureau a créé le compte à la main
     * avant la reprise, on ne veut pas d'un doublon.
     */
    private function resolveExisting(int $joomlaId, string $email): int
    {
        $byMark = get_users([
            'meta_key'    => self::JOOMLA_ID_META,
            'meta_value'  => (string) $joomlaId,
            'number'      => 1,
            'fields'      => 'ID',
        ]);

        if ($byMark !== []) {
            return (int) $byMark[0];
        }

        $existing = get_user_by('email', $email);

        return $existing instanceof \WP_User ? $existing->ID : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function create(array $row, string $email): int
    {
        $joomlaId = (int) $row['joomla_id'];
        $login    = Sanitizer::login(
            (string) ($row['username'] ?? ''),
            'membre' . $joomlaId
        );

        // Un identifiant peut être libre côté Joomla et pris côté WordPress.
        //
        // `username_exists()` renvoie l'identifiant numérique ou **false** — et
        // jamais null, contrairement à ce que son nom laisse croire. On teste
        // donc la véracité, et on borne la boucle : un compteur non borné qui
        // dépend d'une fonction tierce est un blocage en puissance.
        $base = $login;
        for ($attempt = 2; username_exists($login) && $attempt <= 50; $attempt++) {
            $login = mb_substr($base, 0, 54) . '-' . $attempt;
        }

        if (username_exists($login)) {
            $this->report->skip('comptes', $joomlaId, 'identifiant de connexion introuvable après 50 essais');

            return 0;
        }

        $first = Sanitizer::text($row['firstname'] ?? '', 80);
        $last  = Sanitizer::text($row['lastname'] ?? '', 80);
        $name  = trim($first . ' ' . $last);

        if ($name === '') {
            $name = Sanitizer::text($row['name'] ?? '', 160);
        }

        $userId = wp_insert_user([
            'user_login'    => $login,
            'user_email'    => $email,
            // Aléatoire et jamais journalisé : voir l'en-tête de classe.
            'user_pass'     => wp_generate_password(32, true, true),
            'display_name'  => $name !== '' ? $name : $login,
            'first_name'    => $first,
            'last_name'     => $last,
            'role'          => $this->roleFor($joomlaId, (bool) $row['has_active']),
            'user_registered' => Sanitizer::date($row['registerDate'] ?? null)
                ? Sanitizer::date($row['registerDate'] ?? null) . ' 00:00:00'
                : current_time('mysql'),
        ]);

        if (is_wp_error($userId)) {
            $this->report->skip('comptes', $joomlaId, $userId->get_error_message());

            return 0;
        }

        $userId = (int) $userId;
        update_user_meta($userId, self::JOOMLA_ID_META, (string) $joomlaId);
        $this->applyProfile($userId, $row);
        $this->applyCapabilities($userId, $joomlaId);

        return $userId;
    }

    /**
     * Rôle WordPress correspondant aux groupes Joomla.
     *
     * Les groupes d'administration ne donnent **pas** `administrator` : 14
     * comptes cumulaient ce privilège sur l'ancien site, ce qui fait partie des
     * causes plausibles de la compromission. Ils deviennent `sub_office`, et le
     * club promeut à la main les deux administrateurs qu'il veut garder.
     */
    private function roleFor(int $joomlaId, bool $hasActiveMembership): string
    {
        $groups = $this->groupsOf($joomlaId);

        foreach ([self::GROUP_SUPER, self::GROUP_ADMIN, self::GROUP_MANAGER, self::GROUP_COMMITTEE] as $office) {
            if (in_array($office, $groups, true)) {
                return Roles::OFFICE;
            }
        }

        if (in_array(self::GROUP_DIVE_LEAD, $groups, true)
            || in_array(self::GROUP_MEMBER, $groups, true)
            || $hasActiveMembership) {
            return Roles::MEMBER;
        }

        return Roles::GUEST;
    }

    /**
     * Le directeur de plongée garde le droit de créer des sorties, même s'il
     * n'est pas au bureau : c'est une habilitation technique, pas un mandat.
     */
    private function applyCapabilities(int $userId, int $joomlaId): void
    {
        if (!in_array(self::GROUP_DIVE_LEAD, $this->groupsOf($joomlaId), true)) {
            return;
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return;
        }

        $user->add_cap('sub_create_exploration_event');
        $user->add_cap('sub_create_training_event');
    }

    /** @var array<int, list<int>> */
    private array $groupCache = [];

    /** @return list<int> */
    private function groupsOf(int $joomlaId): array
    {
        if (!isset($this->groupCache[$joomlaId])) {
            $table = $this->source->table('user_usergroup_map');
            $rows  = $this->source->rows(
                "SELECT group_id FROM {$table} WHERE user_id = %d",
                [$joomlaId]
            );

            $this->groupCache[$joomlaId] = array_map(
                static fn (array $r): int => (int) $r['group_id'],
                $rows
            );
        }

        return $this->groupCache[$joomlaId];
    }

    /**
     * Reprend les données de profil Community Builder.
     *
     * @param array<string, mixed> $row
     */
    private function applyProfile(int $userId, array $row): void
    {
        $phone = Sanitizer::phone($row['cb_mobile'] ?? null);
        if ($phone === '') {
            $phone = Sanitizer::phone($row['cb_telres'] ?? null);
        }
        if ($phone !== '') {
            update_user_meta($userId, 'sub_phone', $phone);
        }

        $birth = Sanitizer::date($row['cb_datenaissance'] ?? null);
        if ($birth !== null) {
            update_user_meta($userId, 'sub_birth_date', $birth);
        }

        foreach ([
            'sub_address'      => 'cb_adresse',
            'sub_postal_code'  => 'cb_codepostal',
            'sub_city'         => 'cb_ville',
            'sub_licence'      => 'cb_numlicence',
        ] as $meta => $column) {
            $value = Sanitizer::text($row[$column] ?? null, 190);
            if ($value !== '') {
                update_user_meta($userId, $meta, $value);
            }
        }

        $this->applyDiveLevel($userId, (string) ($row['cb_niveauplongee'] ?? ''));
    }

    /**
     * Rattache le niveau de plongée à la taxonomie du plugin.
     *
     * Les libellés Joomla (« P4/E2 ») correspondent déjà aux niveaux du plugin ;
     * un niveau inconnu est **signalé** plutôt que créé à la volée — inventer un
     * niveau de plongée serait une faute, c'est une donnée fédérale.
     */
    private function applyDiveLevel(int $userId, string $raw): void
    {
        $term = $this->resolveDiveLevel($raw, $userId, 'compte');

        if ($term instanceof \WP_Term) {
            update_user_meta($userId, 'sub_dive_level_id', (string) $term->term_id);
        }
    }

    /**
     * Traduit un libellé Joomla en niveau du plugin, ou signale l'inconnu.
     *
     * Un niveau non reconnu n'est jamais créé à la volée : les niveaux sont une
     * donnée fédérale FFESSM, pas du texte libre. En inventer un ferait entrer
     * dans le référentiel une valeur que personne n'a validée.
     */
    private function resolveDiveLevel(string $raw, int $reference, string $kind): ?\WP_Term
    {
        $label = trim($raw);
        if ($label === '') {
            return null;
        }

        $slug = strtolower(str_replace('/', '-', $label));
        $term = get_term_by('slug', $slug, DiveLevels::TAXONOMY)
            ?: get_term_by('name', $label, DiveLevels::TAXONOMY);

        if (!$term instanceof \WP_Term) {
            $this->report->warn(sprintf(
                'Niveau de plongée inconnu « %s » (%s %d) — à rattacher à la main.',
                $label,
                $kind,
                $reference
            ));

            return null;
        }

        return $term;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function describe(array $row, string $email): string
    {
        $motifs = [];
        if ((bool) $row['has_active']) {
            $motifs[] = 'adhésion active';
        }
        if ((bool) $row['has_duty']) {
            $motifs[] = 'responsabilité';
        }
        if ($motifs === []) {
            $motifs[] = 'connexion récente';
        }

        return sprintf('%s <%s> — %s', Sanitizer::text($row['name'] ?? '', 60), $email, implode(', ', $motifs));
    }
}
