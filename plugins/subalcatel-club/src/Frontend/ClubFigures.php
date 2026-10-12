<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Identity\DiveLevels;
use Subalcatel\Club\Identity\TechnicalAccounts;

/**
 * Chiffres du club dans le texte des pages : raccourci [subalcatel_chiffre].
 *
 *   Le club réunit [subalcatel_chiffre stat="adherents"] adhérents.
 *   Plus de [subalcatel_chiffre stat="adherents" arrondi="10"] adhérents.
 *
 * Même logique que [PricingTable] pour les tarifs : un effectif recopié à la
 * main est faux dès la saison suivante. Le Joomla annonçait « environ 70
 * adhérents » quand la base en comptait 86, et le thème de démo « 128 » qui
 * n'avaient jamais existé.
 *
 * On compte les **adhésions à jour** — celles dont l'échéance n'est pas passée —
 * hors comptes techniques, qui ne sont pas des adhérents. L'encadrement se lit
 * sur le rang d'enseignement du niveau de plongée, pas sur une liste de
 * libellés : si le bureau intercale un niveau, le compte suit.
 *
 * Le raccourci rend un nombre nu, sans phrase autour : c'est au rédacteur de
 * la page d'écrire le texte, et d'accorder « adhérent » au pluriel.
 */
final class ClubFigures
{
    /**
     * Statistiques proposées, et le niveau qui ouvre chacune.
     *
     * `null` : toute adhésion à jour compte. Sinon, l'identifiant du niveau
     * dont le rang d'enseignement sert de seuil — « moniteurs » commence au
     * MF1 (E3), « mf2 » au MF2 (E4), « encadrants » au premier brevet (E1).
     */
    private const STATS = [
        'adherents'  => null,
        'encadrants' => 'p2-e1',
        'moniteurs'  => 'e3',
        'mf2'        => 'e4',
    ];

    public static function register(): void
    {
        add_shortcode('subalcatel_chiffre', [self::class, 'render']);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public static function render($atts = []): string
    {
        $atts = shortcode_atts(['stat' => 'adherents', 'arrondi' => '1'], (array) $atts, 'subalcatel_chiffre');

        $stat = sanitize_key((string) $atts['stat']);

        if (!array_key_exists($stat, self::STATS)) {
            return '';
        }

        $count = self::count($stat);
        $step  = max(1, (int) $atts['arrondi']);

        // Arrondi vers le bas : « plus de 80 » doit rester vrai jusqu'à la
        // dernière adhésion qui expire, jamais promettre un effectif absent.
        return esc_html(number_format_i18n(intdiv($count, $step) * $step));
    }

    public static function count(string $stat): int
    {
        if (!array_key_exists($stat, self::STATS)) {
            return 0;
        }

        $ranks = self::activeMemberTeachingRanks();
        $slug  = self::STATS[$stat];

        if ($slug === null) {
            return count($ranks);
        }

        $threshold = DiveLevels::rankOfSlug($slug, DiveLevels::RANK_TEACHING);

        // Niveau supprimé ou rang effacé : mieux vaut zéro qu'un seuil à zéro,
        // qui ferait passer tous les adhérents pour des moniteurs.
        if ($threshold === 0) {
            return 0;
        }

        return count(array_filter($ranks, static fn (int $rank): bool => $rank >= $threshold));
    }

    /**
     * Rang d'enseignement de chaque adhérent à jour, indexé par compte.
     *
     * Une seule requête pour tout l'effectif, quelle que soit sa taille.
     *
     * @return array<int, int>
     */
    private static function activeMemberTeachingRanks(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.user_id, MAX(CAST(COALESCE(t.meta_value, '0') AS UNSIGNED)) AS teaching
             FROM {$wpdb->usermeta} m
             LEFT JOIN {$wpdb->usermeta} l
               ON l.user_id = m.user_id AND l.meta_key = 'sub_dive_level_id'
             LEFT JOIN {$wpdb->termmeta} t
               ON t.term_id = l.meta_value AND t.meta_key = %s
             WHERE m.meta_key = 'sub_membership_valid_until' AND m.meta_value >= %s
             GROUP BY m.user_id",
            DiveLevels::RANK_TEACHING,
            current_time('Y-m-d')
        ), ARRAY_A) ?: [];

        $ranks = [];

        foreach ($rows as $row) {
            $ranks[(int) $row['user_id']] = (int) $row['teaching'];
        }

        $kept = TechnicalAccounts::filter(array_keys($ranks));

        return array_intersect_key($ranks, array_flip($kept));
    }
}
