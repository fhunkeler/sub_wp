<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

/**
 * Résolution des pages du site.
 *
 * Les liens internes cherchent la page par son chemin plutôt que d'être écrits
 * en dur : le club peut renommer une page sans que rien ne casse. Une page
 * absente ne produit pas de lien mort — elle ne produit pas de lien du tout.
 *
 * Les constantes ci-dessous sont les chemins *par défaut*, ceux qu'installe
 * `SiteBuilder`. Si le bureau réorganise l'arborescence, la résolution suit :
 * chaque page installée porte une clé en méta, et c'est elle qui fait foi.
 */
final class Pages
{
    /** Méta portant la clé fonctionnelle d'une page installée. */
    public const KEY_META = '_sub_page_key';

    public const HOME        = 'accueil';
    public const MEMBER_AREA = 'espace-membre';
    public const PROFILE     = 'espace-membre/profil';
    public const MY_DOCUMENTS = 'espace-membre/profil/documents';
    public const MEMBERSHIP  = 'espace-membre/adhesion';
    public const SUBSCRIBE   = 'espace-membre/adhesion/souscrire';
    public const AGENDA      = 'espace-membre/agenda';
    public const CALENDAR    = 'espace-membre/agenda/calendrier';
    public const NEW_OUTING  = 'espace-membre/agenda/organiser-une-sortie';
    public const MY_OUTINGS  = 'espace-membre/agenda/mes-sorties-organisees';
    public const REGISTRATIONS = 'espace-membre/inscriptions';
    public const CLUB_DOCUMENTS = 'espace-membre/documents';

    public const NEWS        = 'actualites';
    public const PUBLIC_AGENDA = 'agenda';
    public const JOIN        = 'nous-rejoindre';
    public const PRICING     = 'nous-rejoindre/tarifs';
    public const HOW_TO_JOIN = 'nous-rejoindre/adherer';
    public const CONTACT     = 'contact';
    public const LOGIN       = 'connexion';
    public const SIGNUP      = 'creer-mon-compte';
    public const PRIVACY     = 'confidentialite';

    /**
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * @var array<string, string>|null
     */
    private static ?array $aliases = null;

    /**
     * URL d'une page, par sa clé fonctionnelle ou son chemin.
     *
     * La recherche par clé passe en premier : elle survit à un déplacement de
     * la page dans l'arborescence, contrairement au chemin.
     */
    public static function url(string $key): string
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $page = self::resolve($key);
        $url  = $page instanceof \WP_Post ? (string) get_permalink($page) : '';

        return self::$cache[$key] = $url;
    }

    public static function exists(string $key): bool
    {
        return self::url($key) !== '';
    }

    public static function id(string $key): int
    {
        $page = self::resolve($key);

        return $page instanceof \WP_Post ? $page->ID : 0;
    }

    /**
     * Trois pistes, dans cet ordre : la clé fonctionnelle, le chemin, puis les
     * anciens chemins déclarés dans le plan du site.
     *
     * L'alias vient en dernier à dessein : si le bureau crée une vraie page à ce
     * chemin, c'est elle qui doit répondre, pas la redirection historique.
     */
    private static function resolve(string $key): ?\WP_Post
    {
        $page = self::findByKey($key) ?? get_page_by_path($key);

        if ($page instanceof \WP_Post) {
            return $page;
        }

        $canonical = self::aliases()[$key] ?? null;

        if ($canonical === null || $canonical === $key) {
            return null;
        }

        return self::findByKey($canonical) ?? get_page_by_path($canonical);
    }

    /**
     * Anciens chemins → clé actuelle, lus dans le plan du site.
     *
     * `SiteMap` déclare déjà, pour chaque page, les chemins qu'elle a pu prendre
     * avant l'installation ; `SiteBuilder` s'en sert pour ne pas dupliquer les
     * pages. La résolution des liens s'appuie sur la même déclaration, faute de
     * quoi un lien écrit avec l'ancien chemin ne mène nulle part — et comme une
     * page introuvable ne produit aucun lien, le bouton disparaît en silence.
     *
     * @return array<string, string>
     */
    private static function aliases(): array
    {
        if (self::$aliases !== null) {
            return self::$aliases;
        }

        self::$aliases = [];

        foreach (\Subalcatel\Club\Setup\SiteMap::pages() as $page) {
            foreach ((array) ($page['legacy'] ?? []) as $legacy) {
                self::$aliases[(string) $legacy] = (string) $page['key'];
            }
        }

        return self::$aliases;
    }

    private static function findByKey(string $key): ?\WP_Post
    {
        $found = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => self::KEY_META,
            'meta_value'     => $key,
            // Résolution interne : le filtre de visibilité masquerait les pages
            // de l'espace membre au plugin lui-même.
            \Subalcatel\Club\Content\Visibility::BYPASS => true,
        ]);

        return $found === [] ? null : $found[0];
    }

    /**
     * Vide le cache — utile après une réinstallation des pages.
     */
    public static function forget(): void
    {
        self::$cache   = [];
        self::$aliases = null;
    }
}
