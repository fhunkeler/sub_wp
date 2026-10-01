<?php

declare(strict_types=1);

namespace Subalcatel\Club\Notifications;

/**
 * Plafond quotidien d'envoi.
 *
 * Le site envoie par un service transactionnel dont l'offre gratuite plafonne
 * le nombre de messages par jour (Brevo : 300). Le dépasser ne prévient
 * personne : les messages suivants sont refusés en silence, ou basculent sur
 * la connexion de secours — une boîte OVH, celle-là même qu'une rafale de
 * soixante annonces a fait bloquer pour spam le 30/09/2026.
 *
 * Le compteur suit tout ce qui part du site, réinitialisations de mot de passe
 * de WordPress comprises : elles consomment le même quota. Seuls les envois
 * groupés sont freinés. Un message individuel — un lien de mot de passe, la
 * confirmation d'un dossier — n'attend pas : la réserve existe pour lui.
 */
final class SendQuota
{
    public const OPTION_SETTINGS = 'subalcatel_mail_quota';
    public const OPTION_COUNTER  = 'subalcatel_mail_sent_today';

    public const DEFAULT_LIMIT   = 300;
    public const DEFAULT_RESERVE = 50;

    /** Rappels reportés depuis le début de la requête, pour le journal de l'entretien. */
    private static int $deferred = 0;

    public static function register(): void
    {
        add_action('wp_mail_succeeded', [self::class, 'record']);
    }

    /**
     * @param array<string, mixed> $mail Données transmises par `wp_mail_succeeded`.
     */
    public static function record(array $mail): void
    {
        // Le service décompte par destinataire, pas par message.
        $recipients = count((array) ($mail['to'] ?? []));

        foreach ((array) ($mail['headers'] ?? []) as $header) {
            if (preg_match('/^\s*b?cc\s*:(.*)$/i', (string) $header, $m)) {
                $recipients += count(array_filter(array_map('trim', explode(',', $m[1]))));
            }
        }

        self::add(max(1, $recipients));
    }

    public static function add(int $count): void
    {
        $today   = current_time('Y-m-d');
        $counter = (array) get_option(self::OPTION_COUNTER, []);
        $sent    = ($counter['day'] ?? '') === $today ? (int) ($counter['count'] ?? 0) : 0;

        update_option(self::OPTION_COUNTER, ['day' => $today, 'count' => $sent + $count], false);
    }

    public static function sentToday(): int
    {
        $counter = (array) get_option(self::OPTION_COUNTER, []);

        return ($counter['day'] ?? '') === current_time('Y-m-d') ? (int) ($counter['count'] ?? 0) : 0;
    }

    /**
     * @return array{limit: int, reserve: int}
     */
    public static function settings(): array
    {
        $saved = (array) get_option(self::OPTION_SETTINGS, []);

        return [
            'limit'   => max(0, (int) ($saved['limit'] ?? self::DEFAULT_LIMIT)),
            'reserve' => max(0, (int) ($saved['reserve'] ?? self::DEFAULT_RESERVE)),
        ];
    }

    public static function save(int $limit, int $reserve): void
    {
        update_option(self::OPTION_SETTINGS, [
            'limit'   => max(0, $limit),
            'reserve' => max(0, $reserve),
        ], false);
    }

    /**
     * Envois groupés encore possibles aujourd'hui ; `null` sans plafond.
     */
    public static function bulkRemaining(): ?int
    {
        $settings = self::settings();

        if ($settings['limit'] === 0) {
            return null;
        }

        return max(0, $settings['limit'] - $settings['reserve'] - self::sentToday());
    }

    public static function allowsBulk(int $messages): bool
    {
        $remaining = self::bulkRemaining();

        return $remaining === null || $messages <= $remaining;
    }

    public static function noteDeferred(): void
    {
        self::$deferred++;
    }

    public static function deferredCount(): int
    {
        return self::$deferred;
    }

    /**
     * Refuse d'avance un envoi groupé qui ne tiendrait pas dans la journée.
     *
     * Tout ou rien : une sortie annoncée à la moitié du club aujourd'hui et à
     * l'autre demain donne les places aux premiers servis par le hasard de
     * l'ordre alphabétique.
     *
     * @throws QuotaExceeded
     */
    public static function ensureBulk(int $messages): void
    {
        if (self::allowsBulk($messages)) {
            return;
        }

        throw new QuotaExceeded(sprintf(
            'Envoi refusé : %d messages à envoyer, mais il n’en reste que %d possibles aujourd’hui '
            . 'avant le plafond quotidien (%d, dont %d réservés aux messages individuels). '
            . 'Réessayez demain.',
            $messages,
            (int) self::bulkRemaining(),
            self::settings()['limit'],
            self::settings()['reserve']
        ));
    }
}
