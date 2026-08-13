<?php

declare(strict_types=1);

namespace Subalcatel\Club\Identity;

use WP_Term;

/**
 * Niveaux de plongée.
 *
 * Taxonomie privée plutôt que liste en dur : le bureau doit pouvoir ajouter un
 * niveau sans développeur. Chaque niveau porte trois drapeaux qui déterminent
 * les droits — c'est ce qui remplace les rôles « encadrant » et « directeur de
 * plongée » du cahier des charges.
 */
final class DiveLevels
{
    public const TAXONOMY = 'sub_dive_level';

    public const FLAG_AUTONOMOUS = 'autonome';
    public const FLAG_INSTRUCTOR = 'encadrant';
    public const FLAG_DIVE_LEADER = 'directeur_plongee';

    /**
     * Rangs ordinaux, sur deux axes indépendants.
     *
     * Un niveau de plongée n'est pas une étiquette dans un sac : c'est un rang.
     * Un P5 exerce tout ce qu'exerce un P2 ; l'inverse est faux. Et « P5/E2 »
     * n'est pas un niveau, c'en est **deux** — un rang de plongeur et un rang
     * d'enseignement, qui progressent séparément.
     *
     * D'où deux méta et non une : `RANK_DIVER` porte l'axe plongeur (P0 → P5),
     * `RANK_TEACHING` l'axe encadrement (E1 → E4). Comparer sur ces rangs, au
     * lieu de vérifier l'appartenance à une liste, est ce qui permet à un P5/E2
     * de s'inscrire à une plongée ouverte au P2.
     */
    public const RANK_DIVER    = 'rang_plongeur';
    public const RANK_TEACHING = 'rang_encadrement';

    /**
     * Niveaux initiaux :
     * [libellé, autonome, encadrant, directeur de plongée, rang plongeur, rang encadrement].
     *
     * Source des drapeaux : wordpress/projet.md — autonome de P3 à E4,
     * directeur de plongée P5/P5-E2/E3/E4.
     *
     * Source des rangs : le Code du sport et l'article « Niveaux de plongée en
     * scaphandre autonome en France ». Deux faits y sont structurants et se
     * lisent directement dans la table :
     *
     *   - un brevet d'encadrement **présuppose** un niveau de plongeur : E1 est
     *     au moins P2, E2 est P4, E3 et E4 sont de fait P5. C'est pourquoi
     *     « E3 » et « E4 » portent ici le rang plongeur de P5 alors que leur
     *     libellé ne le dit pas ;
     *   - les aptitudes PE (encadré) et PA (autonome) sont des jalons entre les
     *     niveaux : P1 vaut PE20, P2 vaut PE40 + PA20, P3 vaut PA40 + PE60.
     *
     * Les rangs vont de 10 en 10 : le bureau peut intercaler un niveau sans
     * renuméroter, depuis Club → Réglages.
     *
     * À FAIRE (question C1) : faire valider par un encadrant. Deux points
     * restent des choix et non des faits. Le classement relatif des aptitudes
     * PE/PA d'abord — PE40 (encadré à 40 m) et PA20 (autonome à 20 m) ne se
     * comparent pas vraiment, elles mesurent deux choses ; l'ordre retenu ici
     * les range par exigence croissante de formation, ce qui est pratique mais
     * discutable. Le cas des qualifications PA20 / PA40 ensuite, qui désignent
     * littéralement une autonomie limitée en profondeur et sont pourtant NON
     * autonomes ici, conformément à la lettre de projet.md.
     *
     * @var list<array{0: string, 1: bool, 2: bool, 3: bool, 4: int, 5: int}>
     */
    private const SEED = [
        ['NAP',         false, false, false,   0,  0],
        ['P0',          false, false, false,   0,  0],
        ['Plongeur Or', false, false, false,  10,  0],
        ['PE12',        false, false, false,  20,  0],
        ['PA12',        false, false, false,  30,  0],
        ['P1',          false, false, false,  40,  0],
        ['PA20',        false, false, false,  50,  0],
        ['PE40',        false, false, false,  60,  0],
        ['P2',          false, false, false,  70,  0],
        ['P2/E1',       false, true,  false,  70, 10],
        ['PE60',        false, false, false,  80,  0],
        ['PA40',        false, false, false,  90,  0],
        ['P3',          true,  false, false, 100,  0],
        ['P3/E1',       true,  true,  false, 100, 10],
        ['P4',          true,  false, false, 110,  0],
        ['P4/E2',       true,  true,  false, 110, 20],
        ['P5',          true,  false, true,  120,  0],
        ['P5/E2',       true,  true,  true,  120, 20],
        ['E3',          true,  true,  true,  120, 30],
        ['E4',          true,  true,  true,  120, 40],
    ];

    public static function register(): void
    {
        register_taxonomy(self::TAXONOMY, 'user', [
            'public'             => false,
            // WordPress ne fournit AUCUN écran d'administration pour les
            // taxonomies rattachées à l'objet `user` : ni show_ui ni
            // show_in_menu n'ont d'effet ici. L'écran de gestion des niveaux
            // est donc à construire dans le menu « Club ».
            'show_ui'            => false,
            'show_in_menu'       => false,
            'hierarchical'       => false,
            // Taxonomie interne rattachée aux comptes : aucune URL publique,
            // donc aucune règle de réécriture à générer.
            'rewrite'            => false,
            'query_var'          => false,
            'labels'             => [
                'name'          => 'Niveaux de plongée',
                'singular_name' => 'Niveau de plongée',
            ],
            'capabilities'       => [
                'manage_terms' => 'sub_manage_memberships',
                'edit_terms'   => 'sub_manage_memberships',
                'delete_terms' => 'sub_manage_memberships',
                'assign_terms' => 'read',
            ],
        ]);
    }

    /**
     * Crée les niveaux initiaux. Idempotent : ne touche pas à un niveau existant,
     * pour ne jamais écraser un réglage fait par le bureau.
     */
    public static function seed(): void
    {
        foreach (self::SEED as [$label, $autonomous, $instructor, $leader, $diver, $teaching]) {
            $slug     = sanitize_title($label);
            $existing = term_exists($slug, self::TAXONOMY);

            if ($existing === null || $existing === 0) {
                $term = wp_insert_term($label, self::TAXONOMY, ['slug' => $slug]);

                if (is_wp_error($term)) {
                    continue;
                }

                $termId = (int) $term['term_id'];

                update_term_meta($termId, self::FLAG_AUTONOMOUS, $autonomous ? '1' : '0');
                update_term_meta($termId, self::FLAG_INSTRUCTOR, $instructor ? '1' : '0');
                update_term_meta($termId, self::FLAG_DIVE_LEADER, $leader ? '1' : '0');
            } else {
                $termId = (int) (is_array($existing) ? $existing['term_id'] : $existing);
            }

            // Les rangs sont arrivés après les niveaux : sur un site déjà
            // installé, les termes existent sans eux et toute comparaison les
            // lirait à zéro — un P5 se retrouverait au rang d'un débutant. On
            // les pose donc aussi sur les termes déjà en place, sans jamais
            // écraser une valeur que le bureau aurait ajustée.
            self::fillRankIfEmpty($termId, self::RANK_DIVER, $diver);
            self::fillRankIfEmpty($termId, self::RANK_TEACHING, $teaching);
        }
    }

    private static function fillRankIfEmpty(int $termId, string $meta, int $rank): void
    {
        if (get_term_meta($termId, $meta, true) === '') {
            update_term_meta($termId, $meta, (string) $rank);
        }
    }

    /**
     * Niveau courant d'un membre, ou null s'il n'en a pas.
     */
    public static function forUser(int $userId): ?WP_Term
    {
        $levelId = get_user_meta($userId, 'sub_dive_level_id', true);
        if ($levelId === '') {
            return null;
        }

        $term = get_term((int) $levelId, self::TAXONOMY);

        return $term instanceof WP_Term ? $term : null;
    }

    public static function hasFlag(int $userId, string $flag): bool
    {
        $level = self::forUser($userId);

        return $level !== null && get_term_meta($level->term_id, $flag, true) === '1';
    }

    /**
     * Le membre peut-il s'inscrire pour encadrer ?
     *
     * Trois portes, et il faut les trois : encadrer ne se réduit pas au brevet
     * d'enseignement. Le P4 est **guide de palanquée** — il encadre en
     * exploration sans être moniteur — et le P5 dirige la plongée. Ne retenir
     * que le drapeau « encadrant » fermerait la case à la moitié de ceux qui
     * encadrent réellement.
     *
     * Le seuil du guide de palanquée est lu dans la table des niveaux plutôt
     * qu'écrit ici : si le bureau renumérote, la règle suit.
     */
    public static function maySupervise(int $userId): bool
    {
        if (self::hasFlag($userId, self::FLAG_INSTRUCTOR) || self::hasFlag($userId, self::FLAG_DIVE_LEADER)) {
            return true;
        }

        $guide = self::rankOfSlug('p4', self::RANK_DIVER);

        if ($guide === 0) {
            return false;
        }

        [$diver] = self::ranksForUser($userId);

        return $diver >= $guide;
    }

    /**
     * Rang d'un niveau sur un axe, par identifiant de terme.
     */
    public static function rankOf(int $termId, string $axis): int
    {
        return (int) get_term_meta($termId, $axis, true);
    }

    /**
     * Rang d'un niveau sur un axe, par son identifiant d'URL.
     */
    public static function rankOfSlug(string $slug, string $axis): int
    {
        $term = get_term_by('slug', $slug, self::TAXONOMY);

        return $term instanceof WP_Term ? self::rankOf($term->term_id, $axis) : 0;
    }

    /**
     * Rangs d'un membre : [plongeur, encadrement].
     *
     * @return array{0: int, 1: int}
     */
    public static function ranksForUser(int $userId): array
    {
        $level = self::forUser($userId);

        if ($level === null) {
            return [0, 0];
        }

        return [
            self::rankOf($level->term_id, self::RANK_DIVER),
            self::rankOf($level->term_id, self::RANK_TEACHING),
        ];
    }

    /**
     * Tous les niveaux, du plus bas au plus haut.
     *
     * L'ordre alphabétique n'a aucun sens ici — il place E4 avant P1 et PA12
     * avant P0. Partout où l'on présente les niveaux à un humain, c'est cet
     * ordre-ci qu'il faut.
     *
     * @return list<WP_Term>
     */
    public static function ordered(): array
    {
        $terms = get_terms([
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        $terms = array_values(array_filter($terms, static fn ($t): bool => $t instanceof WP_Term));

        usort($terms, static function (WP_Term $a, WP_Term $b): int {
            return [self::rankOf($a->term_id, self::RANK_DIVER), self::rankOf($a->term_id, self::RANK_TEACHING), $a->name]
               <=> [self::rankOf($b->term_id, self::RANK_DIVER), self::rankOf($b->term_id, self::RANK_TEACHING), $b->name];
        });

        return $terms;
    }
}
