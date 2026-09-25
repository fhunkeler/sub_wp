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
     * Fenêtres de rattrapage, une par échéance de rappel.
     *
     * Un rappel visait jusqu'ici une date exacte : « expire dans 30 jours » ne
     * partait que le jour où il restait exactement 30 jours. Une seule journée
     * sans exécution et ce rappel-là ne partait jamais — la tâche ne revient
     * pas en arrière, et WordPress ne rejoue pas une occurrence manquée : deux
     * jours d'arrêt ne font pas deux exécutions au retour, ils en font une.
     *
     * Chaque échéance couvre donc une bande, bornée par l'échéance
     * immédiatement inférieure. Avec des rappels à 60 et 30 jours : la bande
     * J-60 va de J+31 à J+60, la bande J-30 d'aujourd'hui à J+30. Les bandes
     * ne se chevauchent pas et couvrent tout l'intervalle, si bien qu'une
     * échéance reçoit exactement un rappel — celui qui correspond au temps qui
     * lui reste réellement, et non celui du jour où la tâche a fini par
     * tourner.
     *
     * @param  list<int> $days échéances en jours, dans n'importe quel ordre
     * @return list<array{days: int, from: string, to: string}>
     */
    private static function bands(array $days, \DateTimeImmutable $today): array
    {
        $days = array_values(array_unique(array_filter($days, static fn (int $d): bool => $d >= 0)));
        sort($days);

        $bands    = [];
        $previous = -1;

        foreach ($days as $day) {
            $bands[] = [
                'days' => $day,
                'from' => $today->modify('+' . ($previous + 1) . ' days')->format('Y-m-d'),
                'to'   => $today->modify('+' . $day . ' days')->format('Y-m-d'),
            ];
            $previous = $day;
        }

        return $bands;
    }

    /**
     * Jours restants jusqu'à une échéance.
     *
     * Le message annonce une date **et** un nombre de jours ; depuis que le
     * rappel se rattrape, les deux cesseraient de concorder si l'on reprenait
     * l'échéance nominale de la bande — « le 3 mars, dans 30 jours » un
     * 10 février.
     */
    private static function daysUntil(\DateTimeImmutable $today, string $date): int
    {
        $target = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $target === false ? 0 : (int) $today->diff($target)->format('%r%a');
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

            foreach (self::bands($days, $today) as $band) {
                $documents = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}sub_member_documents
                     WHERE type_slug = %s AND status = 'valid'
                       AND valid_until BETWEEN %s AND %s",
                    $type['slug'],
                    $band['from'],
                    $band['to']
                ), ARRAY_A) ?: [];

                foreach ($documents as $document) {
                    $ok = Mailer::toUser(EmailTemplates::DOCUMENT_REMINDER, (int) $document['user_id'], [
                        'document'     => mb_strtolower((string) $type['label']),
                        'fin_validite' => DocumentService::frDate((string) $document['valid_until']),
                        'jours'        => (string) self::daysUntil($today, (string) $document['valid_until']),
                    ], [
                        // La clé d'unicité garde l'échéance NOMINALE de la
                        // bande. L'indexer sur les jours réellement restants
                        // en referait partir un chaque jour, puisque ce nombre
                        // change — c'est exactement ce que `once` empêche.
                        'entity_type' => 'member_document_j' . $band['days'],
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

            foreach (self::bands($days, $today) as $band) {
                $applications = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}sub_applications
                     WHERE campaign_id = %d AND status = 'active'
                       AND valid_until BETWEEN %s AND %s",
                    (int) $campaign['id'],
                    $band['from'],
                    $band['to']
                ), ARRAY_A) ?: [];

                foreach ($applications as $application) {
                    $ok = Mailer::toUser(EmailTemplates::MEMBERSHIP_EXPIRING, (int) $application['user_id'], [
                        'fin_validite' => DocumentService::frDate((string) $application['valid_until']),
                        'jours'        => (string) self::daysUntil($today, (string) $application['valid_until']),
                    ], [
                        // Le suffixe distingue chaque échéance : un rappel à
                        // J-60 ne doit pas empêcher celui de J-30. Il porte
                        // l'échéance nominale de la bande, pas les jours
                        // réellement restants, qui changent chaque jour.
                        'entity_type' => 'application_j' . $band['days'],
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
