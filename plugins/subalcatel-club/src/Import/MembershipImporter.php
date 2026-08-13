<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

use Subalcatel\Club\Membership\ApplicationService;

/**
 * Reprise des adhésions en cours.
 *
 * On ne reprend **que** les adhésions actives : l'historique des saisons
 * passées n'a pas d'usage dans le nouveau site et se conserve très bien dans le
 * dump, qui reste la pièce d'archive. Reprendre 163 souscriptions dont 77
 * périmées encombrerait les écrans du bureau sans rien apporter.
 *
 * Chaque adhésion devient une demande d'adhésion `active` rattachée à une
 * campagne « saison reprise », de sorte que les écrans existants — liste des
 * adhérents, relances, exports — fonctionnent sans cas particulier.
 */
final class MembershipImporter
{
    public const JOOMLA_ID_META = '_sub_joomla_subscriber_id';

    private string $prefix;

    public function __construct(
        private readonly LegacySource $source,
        private readonly Report $report
    ) {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'sub_';
    }

    /**
     * Adhésions actives, avec le plan et le compte associés.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(): array
    {
        $subscribers = $this->source->table('osmembership_subscribers');
        $plans       = $this->source->table('osmembership_plans');

        return $this->source->rows(
            "SELECT s.id, s.user_id, s.plan_id, s.from_date, s.to_date,
                    s.gross_amount, s.created_date, p.title AS plan_title, p.price AS plan_price
             FROM {$subscribers} s
             JOIN {$plans} p ON p.id = s.plan_id
             WHERE s.published = 1
             ORDER BY s.id"
        );
    }

    /**
     * @param  array<int, int> $userMap identifiant Joomla => identifiant WordPress
     */
    public function run(array $userMap, bool $dryRun = true): void
    {
        $rows = $this->candidates();

        if ($rows === []) {
            return;
        }

        $campaignId = $this->ensureCampaign($rows, $dryRun);

        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];
            $userId   = $userMap[(int) $row['user_id']] ?? 0;

            if ($userId === 0) {
                $this->report->skip('adhesions', $legacyId, 'compte non repris');
                continue;
            }

            // Le jeton de simulation ne doit jamais servir de clé étrangère.
            if ($userId === UserImporter::PENDING && !$dryRun) {
                $this->report->skip('adhesions', $legacyId, 'compte non encore créé');
                continue;
            }

            if ($this->alreadyImported($legacyId)) {
                $this->report->skip('adhesions', $legacyId, 'déjà importée');
                continue;
            }

            $validUntil = Sanitizer::date($row['to_date'] ?? null);

            if ($validUntil === null) {
                $this->report->skip('adhesions', $legacyId, 'date de fin illisible');
                continue;
            }

            if ($dryRun) {
                $who = $userId === UserImporter::PENDING
                    ? 'compte à créer'
                    : (get_userdata($userId)?->display_name ?? ('compte ' . $userId));

                $this->report->add('adhesions', $legacyId, sprintf(
                    '%s → %s (jusqu’au %s)',
                    Sanitizer::text($row['plan_title'] ?? '', 40),
                    $who,
                    $validUntil
                ));
                continue;
            }

            $this->createApplication($row, $userId, $campaignId, $validUntil);
            $this->report->add('adhesions', $legacyId, sprintf(
                '%s → compte %d (jusqu’au %s)',
                Sanitizer::text($row['plan_title'] ?? '', 40),
                $userId,
                $validUntil
            ));
        }
    }

    /**
     * Crée — ou retrouve — la campagne correspondant à la saison reprise.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function ensureCampaign(array $rows, bool $dryRun): int
    {
        global $wpdb;

        $ends = array_filter(array_map(
            static fn (array $r): ?string => Sanitizer::date($r['to_date'] ?? null),
            $rows
        ));

        $validUntil = $ends === [] ? gmdate('Y-m-d') : max($ends);
        $season     = (int) substr($validUntil, 0, 4);
        $slug       = sprintf('campagne-%d-%d', $season - 1, $season);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->prefix}campaigns WHERE slug = %s",
            $slug
        ));

        if ($existing !== null) {
            return (int) $existing;
        }

        if ($dryRun) {
            $this->report->add('campagnes', $slug, 'à créer (saison reprise, close)');

            return 0;
        }

        $wpdb->insert("{$this->prefix}campaigns", [
            'title'       => sprintf('Campagne %d-%d', $season - 1, $season),
            'slug'        => $slug,
            'opens_on'    => sprintf('%d-09-01', $season - 1),
            'closes_on'   => $validUntil,
            'valid_from'  => sprintf('%d-09-01', $season - 1),
            'valid_until' => $validUntil,
            // Close : la saison reprise est un état de fait, pas une campagne
            // ouverte aux inscriptions.
            'status'      => 'closed',
            'created_at'  => current_time('mysql'),
        ]);

        $campaignId = (int) $wpdb->insert_id;
        $this->report->add('campagnes', $slug, 'créée (close)');
        $this->ensurePlans($campaignId);

        return $campaignId;
    }

    /**
     * Reprend les plans réels du Joomla, avec leurs tarifs de base réels.
     */
    private function ensurePlans(int $campaignId): void
    {
        global $wpdb;

        $plans = $this->source->rows(
            'SELECT id, title, price FROM ' . $this->source->table('osmembership_plans')
        );

        foreach ($plans as $index => $plan) {
            $title = Sanitizer::text($plan['title'] ?? '', 190);
            $slug  = sanitize_title($title);

            $wpdb->insert("{$this->prefix}plans", [
                'campaign_id' => $campaignId,
                'title'       => $title,
                'slug'        => $slug,
                'description' => '',
                'base_price'  => (float) ($plan['price'] ?? 0),
                'published'   => 1,
                'ordering'    => $index,
            ]);

            update_option(
                'subalcatel_legacy_plan_' . (int) $plan['id'],
                (int) $wpdb->insert_id,
                false
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createApplication(array $row, int $userId, int $campaignId, string $validUntil): void
    {
        global $wpdb;

        $planId    = (int) get_option('subalcatel_legacy_plan_' . (int) $row['plan_id'], 0);
        $validFrom = Sanitizer::date($row['from_date'] ?? null) ?? $validUntil;

        $wpdb->insert("{$this->prefix}applications", [
            'reference'    => 'REP-' . str_pad((string) (int) $row['id'], 6, '0', STR_PAD_LEFT),
            'user_id'      => $userId,
            'campaign_id'  => $campaignId,
            'plan_id'      => $planId,
            'status'       => ApplicationService::STATUS_ACTIVE,
            'total_amount' => (float) ($row['gross_amount'] ?? 0),
            'valid_from'   => $validFrom,
            'valid_until'  => $validUntil,
            'submitted_at' => Sanitizer::date($row['created_date'] ?? null)
                ? Sanitizer::date($row['created_date'] ?? null) . ' 00:00:00'
                : current_time('mysql'),
            'activated_at' => current_time('mysql'),
            'created_at'   => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ]);

        $applicationId = (int) $wpdb->insert_id;

        // La marque d'origine vit sur la demande, via une option dédiée : la
        // table n'a pas de colonne de méta, et on ne la modifie pas pour une
        // reprise ponctuelle.
        update_option(self::JOOMLA_ID_META . '_' . (int) $row['id'], $applicationId, false);

        // C'est cette méta qui commande l'accès aux contenus réservés.
        update_user_meta($userId, 'sub_membership_valid_until', $validUntil);
    }

    private function alreadyImported(int $legacyId): bool
    {
        return (int) get_option(self::JOOMLA_ID_META . '_' . $legacyId, 0) > 0;
    }
}
