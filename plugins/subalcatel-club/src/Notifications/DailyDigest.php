<?php

declare(strict_types=1);

namespace Subalcatel\Club\Notifications;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Documents\DocumentTypes;
use Subalcatel\Club\Identity\LegalGuardian;
use Subalcatel\Club\Support\Audit;

/**
 * Entretien quotidien : expirations, purges et rappels.
 *
 * Une seule tâche pour tout ce qui dépend du calendrier. Les rappels de
 * documents étaient auparavant écrits en dur dans le module Documents ; ils
 * passent maintenant par les mêmes modèles éditables que le reste.
 *
 * WP-Cron ne se déclenche qu'à la visite d'un internaute. Pour un club dont le
 * site est peu fréquenté l'hiver, une purge « quotidienne » pourrait n'arriver
 * qu'au printemps — inacceptable pour une donnée de santé. D'où le cron système
 * à brancher à l'installation (voir readme).
 */
final class DailyDigest
{
    public const HOOK = 'subalcatel_daily';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'run']);

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'daily', self::HOOK);
        }
    }

    public static function unregister(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * @return array{expired: int, purged: int, doc_reminders: int, purge_warnings: int, membership_reminders: int, came_of_age: int}
     */
    public static function run(?string $onDate = null): array
    {
        $documents = new DocumentService();

        $result = [
            'expired'              => $documents->markExpired($onDate),
            'purged'               => $documents->purgeDue($onDate),
            'doc_reminders'        => self::documentReminders($onDate),
            'purge_warnings'       => $documents->warnBeforePurge(15, $onDate),
            'membership_reminders' => self::membershipReminders($onDate),
            'came_of_age'          => self::comeOfAge($onDate),
        ];

        Audit::log('notifications.daily', 'system', null, $result);

        return $result;
    }

    /**
     * Bascule les comptes devenus majeurs.
     *
     * Attendre que quelqu'un y pense reviendrait à garder un adulte sous
     * tutelle pendant des années — et à conserver les coordonnées de ses
     * parents sans motif.
     */
    private static function comeOfAge(?string $onDate = null): int
    {
        $count = 0;

        foreach (LegalGuardian::newlyOfAge($onDate) as $userId) {
            LegalGuardian::comeOfAge($userId);
            $count++;
        }

        return $count;
    }

    /**
     * Rappels avant expiration d'un document, selon les délais de son type.
     */
    private static function documentReminders(?string $onDate = null): int
    {
        global $wpdb;

        $today = new \DateTimeImmutable($onDate ?? current_time('Y-m-d'));
        $sent  = 0;

        foreach (DocumentTypes::all() as $type) {
            $days = array_filter(array_map('intval', explode(',', (string) $type['reminder_days'])));

            foreach ($days as $day) {
                $target = $today->modify('+' . $day . ' days')->format('Y-m-d');

                $documents = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}sub_member_documents
                     WHERE type_slug = %s AND status = 'valid' AND valid_until = %s",
                    $type['slug'],
                    $target
                ), ARRAY_A) ?: [];

                foreach ($documents as $document) {
                    $ok = Mailer::toUser(EmailTemplates::DOCUMENT_REMINDER, (int) $document['user_id'], [
                        'document'     => mb_strtolower((string) $type['label']),
                        'fin_validite' => DocumentService::frDate((string) $document['valid_until']),
                        'jours'        => (string) $day,
                    ], [
                        'entity_type' => 'member_document_j' . $day,
                        'entity_id'   => (int) $document['id'],
                        'once'        => true,
                    ]);

                    $sent += $ok ? 1 : 0;
                }
            }
        }

        return $sent;
    }

    /**
     * Rappels avant la fin d'une adhésion, selon les délais de la campagne.
     */
    private static function membershipReminders(?string $onDate = null): int
    {
        global $wpdb;

        $today = new \DateTimeImmutable($onDate ?? current_time('Y-m-d'));
        $sent  = 0;

        $campaigns = $wpdb->get_results(
            "SELECT id, reminder_days FROM {$wpdb->prefix}sub_campaigns",
            ARRAY_A
        ) ?: [];

        foreach ($campaigns as $campaign) {
            $days = array_filter(array_map('intval', explode(',', (string) $campaign['reminder_days'])));

            foreach ($days as $day) {
                $target = $today->modify('+' . $day . ' days')->format('Y-m-d');

                $applications = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}sub_applications
                     WHERE campaign_id = %d AND status = 'active' AND valid_until = %s",
                    (int) $campaign['id'],
                    $target
                ), ARRAY_A) ?: [];

                foreach ($applications as $application) {
                    $ok = Mailer::toUser(EmailTemplates::MEMBERSHIP_EXPIRING, (int) $application['user_id'], [
                        'fin_validite' => DocumentService::frDate((string) $application['valid_until']),
                        'jours'        => (string) $day,
                    ], [
                        // Le suffixe distingue chaque échéance : un rappel à
                        // J-60 ne doit pas empêcher celui de J-30.
                        'entity_type' => 'application_j' . $day,
                        'entity_id'   => (int) $application['id'],
                        'once'        => true,
                    ]);

                    $sent += $ok ? 1 : 0;
                }
            }
        }

        return $sent;
    }
}
