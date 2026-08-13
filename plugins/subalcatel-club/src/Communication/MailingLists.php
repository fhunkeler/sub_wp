<?php

declare(strict_types=1);

namespace Subalcatel\Club\Communication;

use Subalcatel\Club\Identity\DiveLevels;

/**
 * Listes de diffusion, calculées et jamais tenues à la main.
 *
 * Une liste maintenue manuellement est fausse au bout d'une saison : personne
 * ne pense à retirer celui qui n'a pas renouvelé. Les listes sont donc des
 * *requêtes* — leur composition se recalcule à chaque envoi.
 *
 * Les listes par niveau ne sont pas énumérées : chaque terme de la taxonomie
 * produit la sienne. Ajouter un niveau crée sa liste sans une ligne de code.
 *
 * Ce module ne remplace pas AcyMailing dans son rôle d'expéditeur — la
 * rédaction, l'envoi en masse et les statistiques restent à une extension
 * dédiée. Il fournit ce qu'aucune extension ne peut deviner : qui, chez ce
 * club, appartient à quoi.
 */
final class MailingLists
{
    public const ACTIVE     = 'adherents-actifs';
    public const FORMER     = 'anciens-adherents';
    public const OFFICE     = 'bureau';
    public const INSTRUCTOR = 'encadrants';
    public const LEADER     = 'directeurs-plongee';

    public const LEVEL_PREFIX = 'niveau-';
    public const GROUP_PREFIX = 'groupe-';

    /** Un ancien adhérent le reste deux ans, puis sort des listes. */
    private const FORMER_YEARS = 2;

    /**
     * Toutes les listes disponibles.
     *
     * @return list<array{slug: string, label: string, description: string, dynamic: bool}>
     */
    public static function all(): array
    {
        $lists = [
            [
                'slug'        => self::ACTIVE,
                'label'       => 'Adhérents actifs',
                'description' => 'Adhésion valide à la date du jour.',
                'dynamic'     => true,
            ],
            [
                'slug'        => self::FORMER,
                'label'       => 'Anciens adhérents',
                'description' => sprintf('Adhésion expirée depuis moins de %d ans.', self::FORMER_YEARS),
                'dynamic'     => true,
            ],
            [
                'slug'        => self::OFFICE,
                'label'       => 'Bureau',
                'description' => 'Porteurs de la capacité de gestion des adhésions.',
                'dynamic'     => true,
            ],
            [
                'slug'        => self::INSTRUCTOR,
                'label'       => 'Encadrants',
                'description' => 'Niveau marqué « encadrant ».',
                'dynamic'     => true,
            ],
            [
                'slug'        => self::LEADER,
                'label'       => 'Directeurs de plongée',
                'description' => 'Niveau marqué « directeur de plongée ».',
                'dynamic'     => true,
            ],
        ];

        foreach (self::levels() as $term) {
            $lists[] = [
                'slug'        => self::LEVEL_PREFIX . $term->slug,
                'label'       => 'Niveau ' . $term->name,
                'description' => 'Membres titulaires de ce niveau.',
                'dynamic'     => true,
            ];
        }

        foreach (CustomGroups::all() as $group) {
            $lists[] = [
                'slug'        => self::GROUP_PREFIX . $group['slug'],
                'label'       => $group['label'],
                'description' => (string) $group['description'],
                'dynamic'     => false,
            ];
        }

        /** @var list<array{slug: string, label: string, description: string, dynamic: bool}> */
        return apply_filters('subalcatel_mailing_lists', $lists);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $list) {
            if ($list['slug'] === $slug) {
                return $list;
            }
        }

        return null;
    }

    /**
     * Identifiants des membres de la liste.
     *
     * @return list<int>
     */
    public static function members(string $slug): array
    {
        if (str_starts_with($slug, self::LEVEL_PREFIX)) {
            return self::byLevel(substr($slug, strlen(self::LEVEL_PREFIX)));
        }

        if (str_starts_with($slug, self::GROUP_PREFIX)) {
            return CustomGroups::members(substr($slug, strlen(self::GROUP_PREFIX)));
        }

        $ids = match ($slug) {
            self::ACTIVE     => self::activeMembers(),
            self::FORMER     => self::formerMembers(),
            self::OFFICE     => self::byCapability('sub_manage_memberships'),
            self::INSTRUCTOR => self::byFlag(DiveLevels::FLAG_INSTRUCTOR),
            self::LEADER     => self::byFlag(DiveLevels::FLAG_DIVE_LEADER),
            default          => [],
        };

        /** @var list<int> */
        return apply_filters('subalcatel_mailing_list_members', $ids, $slug);
    }

    /**
     * Destinataires réels : membres de la liste **et** consentants.
     *
     * C'est la seule méthode qu'une campagne doit appeler. `members()` dit qui
     * appartient au groupe ; celle-ci dit à qui on a le droit d'écrire. Être
     * adhérent ne vaut pas consentement à recevoir la lettre d'information.
     *
     * @return list<array{id: int, email: string, name: string}>
     */
    public static function recipients(string $slug): array
    {
        $recipients = [];

        foreach (self::members($slug) as $userId) {
            if (!Subscriptions::isSubscribed($userId)) {
                continue;
            }

            $user = get_userdata($userId);

            if (!$user || !is_email($user->user_email)) {
                continue;
            }

            $recipients[] = [
                'id'    => $userId,
                'email' => $user->user_email,
                'name'  => $user->display_name,
            ];
        }

        return $recipients;
    }

    /**
     * Effectifs d'une liste : présents, et parmi eux les abonnés.
     *
     * L'écart entre les deux est l'information utile — il dit au bureau
     * combien de personnes il ne touchera pas par ce canal.
     *
     * @return array{members: int, subscribed: int}
     */
    public static function count(string $slug): array
    {
        $members = self::members($slug);

        $subscribed = array_filter($members, static fn (int $id): bool => Subscriptions::isSubscribed($id));

        return ['members' => count($members), 'subscribed' => count($subscribed)];
    }

    /**
     * @return list<int>
     */
    private static function activeMembers(): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'sub_membership_valid_until' AND meta_value >= %s",
            current_time('Y-m-d')
        ));

        return array_map('intval', $ids);
    }

    /**
     * @return list<int>
     */
    private static function formerMembers(): array
    {
        global $wpdb;

        $today = current_time('Y-m-d');
        $floor = gmdate('Y-m-d', strtotime($today . ' -' . self::FORMER_YEARS . ' years'));

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'sub_membership_valid_until'
               AND meta_value < %s AND meta_value >= %s",
            $today,
            $floor
        ));

        return array_map('intval', $ids);
    }

    /**
     * @return list<int>
     */
    private static function byCapability(string $capability): array
    {
        $ids = [];

        foreach (get_users(['fields' => 'ID']) as $userId) {
            if (user_can((int) $userId, $capability)) {
                $ids[] = (int) $userId;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function byFlag(string $flag): array
    {
        $levels = [];

        foreach (self::levels() as $term) {
            if (get_term_meta($term->term_id, $flag, true) === '1') {
                $levels[] = $term->term_id;
            }
        }

        return $levels === [] ? [] : self::byLevelIds($levels);
    }

    /**
     * @return list<int>
     */
    private static function byLevel(string $levelSlug): array
    {
        $term = get_term_by('slug', $levelSlug, DiveLevels::TAXONOMY);

        return $term instanceof \WP_Term ? self::byLevelIds([$term->term_id]) : [];
    }

    /**
     * @param list<int> $levelIds
     * @return list<int>
     */
    private static function byLevelIds(array $levelIds): array
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($levelIds), '%d'));

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'sub_dive_level_id' AND meta_value IN ({$placeholders})",
            ...$levelIds
        ));

        return array_map('intval', $ids);
    }

    /**
     * @return list<\WP_Term>
     */
    private static function levels(): array
    {
        $terms = get_terms([
            'taxonomy'   => DiveLevels::TAXONOMY,
            'hide_empty' => false,
        ]);

        return is_array($terms) ? array_filter($terms, static fn ($t): bool => $t instanceof \WP_Term) : [];
    }
}
