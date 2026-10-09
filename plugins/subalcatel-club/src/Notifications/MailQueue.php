<?php

declare(strict_types=1);

namespace Subalcatel\Club\Notifications;

use Subalcatel\Club\Support\Audit;

/**
 * File d'attente des envois groupés que le plafond du jour ne permet pas.
 *
 * Jusqu'ici, un envoi groupé qui dépassait le plafond était refusé, avec un
 * « réessayez demain » que personne ne pensait à suivre — et une annonce
 * d'ouverture de campagne destinée à plus d'adhérents que le plafond n'en
 * laisse passer en un jour ne pouvait **jamais** partir. La file garde
 * l'envoi, et le reprend d'elle-même dès que le quota le permet.
 *
 * Deux sortes d'envois :
 *
 *  - **d'un bloc** — annonce de sortie, message aux inscrits, annulation :
 *    tout part le même jour, ou rien. Une annonce servie à la moitié du club
 *    aujourd'hui et à l'autre demain donnerait les places aux premiers de
 *    l'ordre alphabétique. Seule exception : un envoi plus gros que ce qu'une
 *    journée entière autorise, qui sinon ne partirait jamais ;
 *  - **fractionnable** — l'ouverture d'une campagne : chacun peut la recevoir
 *    un jour différent, l'essentiel est qu'elle arrive.
 *
 * Un envoi rattaché à une sortie **expire** à son heure de début : prévenir
 * d'une sortie déjà partie n'informe personne.
 *
 * La file est une option, pas une table : elle ne contient presque toujours
 * rien, et quelques lots au pire. Elle est reprise toutes les heures, mais
 * seulement **après** l'entretien quotidien du jour : les rappels d'échéance
 * passent en premier, sans quoi une file chargée les reporterait sans fin.
 */
final class MailQueue
{
    public const OPTION = 'subalcatel_mail_queue';
    public const HOOK   = 'subalcatel_mail_queue';

    /** Dernier jour où l'entretien quotidien a tourné, posé par {@see DailyDigest}. */
    public const OPTION_DAILY_RAN = 'subalcatel_daily_last_run';

    /**
     * Heure à partir de laquelle la file part même si l'entretien du jour n'a
     * pas tourné — une tâche quotidienne en panne ne doit pas bloquer la file.
     */
    private const FALLBACK_HOUR = 12;

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'process']);

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 600, 'hourly', self::HOOK);
        }
    }

    public static function unregister(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Ajoute un lot à la file.
     *
     * @param list<int> $userIds
     * @param array<string, string> $variables
     * @param array<string, mixed> $context
     * @param list<string> $headers
     * @param array{atomic?: bool, expires_at?: ?string, label?: string} $options
     */
    public static function enqueue(
        string $templateCode,
        array $userIds,
        array $variables,
        array $context,
        array $headers,
        array $options,
    ): string {
        $jobs = self::jobs();
        $id   = wp_generate_uuid4();

        $jobs[$id] = [
            'template'   => $templateCode,
            'users'      => array_values(array_map('intval', $userIds)),
            'variables'  => $variables,
            'context'    => array_intersect_key($context, array_flip(['entity_type', 'entity_id', 'sender_id'])),
            'headers'    => $headers,
            'atomic'     => (bool) ($options['atomic'] ?? true),
            'expires_at' => $options['expires_at'] ?? null,
            'label'      => (string) ($options['label'] ?? $templateCode),
            'queued_at'  => current_time('mysql'),
            'queued_by'  => get_current_user_id() ?: null,
            'sent'       => 0,
        ];

        self::save($jobs);

        Audit::log('mail_queue.queued', (string) ($context['entity_type'] ?? 'system'), isset($context['entity_id']) ? (int) $context['entity_id'] : null, [
            'template'   => $templateCode,
            'recipients' => count($userIds),
            'atomic'     => $jobs[$id]['atomic'],
        ]);

        return $id;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function jobs(): array
    {
        $jobs = get_option(self::OPTION, []);

        return is_array($jobs) ? $jobs : [];
    }

    /**
     * Un lot de ce modèle attend-il déjà pour cet objet ?
     */
    public static function hasJobFor(string $templateCode, string $entityType, int $entityId): bool
    {
        foreach (self::jobs() as $job) {
            if ($job['template'] === $templateCode
                && ($job['context']['entity_type'] ?? '') === $entityType
                && (int) ($job['context']['entity_id'] ?? 0) === $entityId) {
                return true;
            }
        }

        return false;
    }

    public static function cancel(string $jobId): bool
    {
        $jobs = self::jobs();

        if (!isset($jobs[$jobId])) {
            return false;
        }

        $job = $jobs[$jobId];
        unset($jobs[$jobId]);
        self::save($jobs);

        Audit::log('mail_queue.cancelled', (string) ($job['context']['entity_type'] ?? 'system'), isset($job['context']['entity_id']) ? (int) $job['context']['entity_id'] : null, [
            'template'  => $job['template'],
            'remaining' => count($job['users']),
        ]);

        return true;
    }

    /**
     * La file est-elle autorisée à partir maintenant ?
     */
    public static function mayRunNow(): bool
    {
        if ((string) get_option(self::OPTION_DAILY_RAN, '') === current_time('Y-m-d')) {
            return true;
        }

        return (int) current_time('G') >= self::FALLBACK_HOUR;
    }

    /**
     * Reprend la file, dans l'ordre d'arrivée.
     *
     * @param bool $force Ignorer la priorité de l'entretien quotidien — c'est
     *                    lui qui appelle, une fois ses rappels partis.
     * @return int messages partis
     */
    public static function process(bool $force = false): int
    {
        if (!$force && !self::mayRunNow()) {
            return 0;
        }

        $jobs = self::jobs();
        $sent = 0;
        $now  = current_time('mysql');

        foreach ($jobs as $id => $job) {
            if (!empty($job['expires_at']) && (string) $job['expires_at'] <= $now) {
                unset($jobs[$id]);

                Audit::log('mail_queue.expired', (string) ($job['context']['entity_type'] ?? 'system'), isset($job['context']['entity_id']) ? (int) $job['context']['entity_id'] : null, [
                    'template'  => $job['template'],
                    'remaining' => count($job['users']),
                ]);
                continue;
            }

            $batch = self::sendable($job);

            if ($batch === []) {
                // Un lot d'un bloc attend une journée où il tient en entier ;
                // les suivants peuvent passer s'ils tiennent, eux.
                continue;
            }

            foreach ($batch as $userId) {
                if (Mailer::toUser($job['template'], $userId, $job['variables'], $job['context'], $job['headers'])) {
                    $sent++;
                    $job['sent']++;
                }
            }

            $job['users'] = array_values(array_diff($job['users'], $batch));

            if ($job['users'] === []) {
                unset($jobs[$id]);

                Audit::log('mail_queue.done', (string) ($job['context']['entity_type'] ?? 'system'), isset($job['context']['entity_id']) ? (int) $job['context']['entity_id'] : null, [
                    'template' => $job['template'],
                    'sent'     => $job['sent'],
                ]);
            } else {
                $jobs[$id] = $job;
            }

            // Enregistré lot par lot : une requête interrompue en cours de file
            // ne fait pas repartir, au passage suivant, ce qui est déjà parti.
            self::save($jobs);
        }

        self::save($jobs);

        return $sent;
    }

    /**
     * Les destinataires de ce lot qui peuvent partir maintenant.
     *
     * @param array<string, mixed> $job
     * @return list<int>
     */
    private static function sendable(array $job): array
    {
        $remaining = SendQuota::bulkRemaining();
        $users     = $job['users'];

        if ($remaining === null || Mailer::messageCount($job['template'], $users) <= $remaining) {
            return $users;
        }

        $settings = SendQuota::settings();
        $capacity = max(0, $settings['limit'] - $settings['reserve']);

        // D'un bloc, sauf s'il ne tiendra jamais en une journée.
        if ($job['atomic'] && Mailer::messageCount($job['template'], $users) <= $capacity) {
            return [];
        }

        $batch = [];

        foreach ($users as $userId) {
            if (Mailer::messageCount($job['template'], array_merge($batch, [$userId])) > $remaining) {
                break;
            }
            $batch[] = $userId;
        }

        return $batch;
    }

    /**
     * La phrase qui dit à l'organisateur ce qui est en attente.
     */
    public static function outcomeNote(int $queued, ?string $expiresAt = null): string
    {
        if ($queued === 0) {
            return '';
        }

        return sprintf(
            ' %d message(s) mis en file d’attente : le plafond d’envoi du jour est atteint. '
            . 'Ils partiront automatiquement dès que le quota le permettra, en principe demain%s.',
            $queued,
            $expiresAt !== null ? ', et au plus tard avant le début de la sortie — sinon ils sont abandonnés' : ''
        );
    }

    /**
     * @param array<string, array<string, mixed>> $jobs
     */
    private static function save(array $jobs): void
    {
        if ($jobs === []) {
            delete_option(self::OPTION);

            return;
        }

        update_option(self::OPTION, $jobs, false);
    }
}
