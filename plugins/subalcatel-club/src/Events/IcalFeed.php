<?php

declare(strict_types=1);

namespace Subalcatel\Club\Events;

use Subalcatel\Club\Frontend\Pages;
use Subalcatel\Club\Support\Audit;

/**
 * Abonnement iCal : l'agenda du club dans Google Agenda ou Apple Calendrier.
 *
 * Un client de calendrier n'ouvre pas de session : il appelle une URL toutes
 * les quelques heures, sans cookie. L'authentification passe donc par un jeton
 * dans l'adresse — la seule méthode que ces clients savent utiliser.
 *
 * Ce jeton est **distinct de celui de la lettre d'information** : il vit dans
 * les serveurs de Google, il est bien plus exposé, et une fuite ne doit pas
 * emporter les deux. Le membre peut le renouveler d'un clic, ce qui invalide
 * immédiatement l'ancienne adresse.
 */
final class IcalFeed
{
    public const ACTION       = 'sub_ical';
    public const ACTION_EVENT = 'sub_ical_event';
    public const ACTION_RESET = 'sub_ical_reset';

    public const FEED_CLUB          = 'club';
    public const FEED_REGISTRATIONS = 'inscriptions';

    public const META_TOKEN = 'sub_ical_token';

    /** Fréquence de rafraîchissement suggérée aux clients, en minutes. */
    private const TTL_MINUTES = 720;

    public static function register(): void
    {
        foreach ([self::ACTION, self::ACTION_EVENT] as $action) {
            add_action('admin_post_' . $action, [self::class, 'handle']);
            add_action('admin_post_nopriv_' . $action, [self::class, 'handle']);
        }

        add_action('admin_post_' . self::ACTION_RESET, [self::class, 'handleReset']);
    }

    /**
     * @return array<string, string>
     */
    public static function feeds(): array
    {
        return [
            self::FEED_CLUB          => 'Agenda du club',
            self::FEED_REGISTRATIONS => 'Mes inscriptions',
        ];
    }

    public static function token(int $userId): string
    {
        $token = (string) get_user_meta($userId, self::META_TOKEN, true);

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            update_user_meta($userId, self::META_TOKEN, $token);
        }

        return $token;
    }

    public static function feedUrl(int $userId, string $feed): string
    {
        return add_query_arg([
            'action' => self::ACTION,
            'feed'   => $feed,
            'user'   => $userId,
            'token'  => self::token($userId),
        ], admin_url('admin-post.php'));
    }

    /**
     * Adresse `webcal://`, qui ouvre directement l'application de calendrier.
     *
     * Même URL, autre protocole : un clic sur `https://` télécharge un fichier
     * figé, un clic sur `webcal://` crée un abonnement qui se met à jour.
     */
    public static function subscribeUrl(int $userId, string $feed): string
    {
        return preg_replace('#^https?://#', 'webcal://', self::feedUrl($userId, $feed)) ?? '';
    }

    public static function eventUrl(int $eventId): string
    {
        return add_query_arg(
            ['action' => self::ACTION_EVENT, 'event' => $eventId],
            admin_url('admin-post.php')
        );
    }

    public static function handle(): void
    {
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action === self::ACTION_EVENT) {
            self::serveEvent();
        }

        $userId = isset($_GET['user']) ? absint($_GET['user']) : 0;
        $token  = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $feed   = isset($_GET['feed']) ? sanitize_key(wp_unslash($_GET['feed'])) : self::FEED_CLUB;

        $expected = (string) get_user_meta($userId, self::META_TOKEN, true);

        // Comparaison en temps constant : sans elle, le jeton se devine octet
        // par octet en mesurant les temps de réponse.
        if ($userId === 0 || $expected === '' || !hash_equals($expected, $token)) {
            self::deny();
        }

        if (!array_key_exists($feed, self::feeds())) {
            $feed = self::FEED_CLUB;
        }

        $events = $feed === self::FEED_REGISTRATIONS
            ? self::registrationsOf($userId)
            : self::clubEvents();

        self::serve(
            $feed === self::FEED_REGISTRATIONS ? 'Mes sorties — Sub Alcatel' : 'Agenda Sub Alcatel',
            $feed === self::FEED_REGISTRATIONS
                ? 'Les sorties auxquelles vous êtes inscrit.'
                : 'Les sorties et rendez-vous du club.',
            $events,
            $feed . '.ics'
        );
    }

    /**
     * Un événement isolé, téléchargé depuis sa fiche.
     */
    private static function serveEvent(): never
    {
        $eventId = isset($_GET['event']) ? absint($_GET['event']) : 0;
        $event   = (new EventService())->find($eventId);

        if ($event === null || $event['status'] !== 'published') {
            self::deny();
        }

        self::serve(
            (string) $event['title'],
            '',
            [$event],
            sanitize_title((string) $event['title']) . '.ics'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function clubEvents(): array
    {
        global $wpdb;

        // Un mois d'historique : un agenda qui ne montre que le futur donne
        // l'impression d'un club sans passé quand on regarde la semaine écoulée.
        $since = gmdate('Y-m-d H:i:s', strtotime('-1 month'));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_events
             WHERE status = 'published' AND starts_at >= %s
             ORDER BY starts_at ASC LIMIT 500",
            $since
        ), ARRAY_A) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function registrationsOf(int $userId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, r.status AS registration_status
             FROM {$wpdb->prefix}sub_event_registrations r
             INNER JOIN {$wpdb->prefix}sub_events e ON e.id = r.event_id
             WHERE r.user_id = %d AND r.status IN ('confirmed','waiting')
             ORDER BY e.starts_at ASC LIMIT 500",
            $userId
        ), ARRAY_A) ?: [];
    }

    /**
     * Propriétés iCalendar d'une ligne d'événement.
     *
     * Une inscription en liste d'attente n'est pas un rendez-vous ferme :
     * marquée « provisoire » et « transparente », elle apparaît dans l'agenda
     * sans bloquer le créneau. Le membre voit qu'il est sur la liste, et son
     * agenda le laisse accepter autre chose au même moment.
     *
     * @param array<string, mixed> $event
     * @return array<string, string>
     */
    public static function propertiesFor(array $event): array
    {
        $properties = IcalWriter::event($event, Pages::url(Pages::AGENDA));

        if (($event['registration_status'] ?? '') === 'waiting') {
            $properties['SUMMARY'] = IcalWriter::escape('[Liste d’attente] ' . $event['title']);
            $properties['STATUS']  = 'TENTATIVE';
            $properties['TRANSP']  = 'TRANSPARENT';
        }

        return $properties;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private static function serve(string $name, string $description, array $events, string $filename): never
    {
        $body = IcalWriter::calendar(
            $name,
            $description,
            array_map([self::class, 'propertiesFor'], $events),
            self::TTL_MINUTES
        );

        nocache_headers();
        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));

        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    public static function handleReset(): void
    {
        $userId = get_current_user_id();

        if ($userId === 0) {
            wp_die('Connectez-vous pour effectuer cette action.');
        }

        check_admin_referer(self::ACTION_RESET . '_' . $userId);

        delete_user_meta($userId, self::META_TOKEN);
        self::token($userId);

        Audit::log('ical.token_reset', 'user', $userId, [], $userId);

        wp_safe_redirect(add_query_arg(
            'sub_done',
            rawurlencode(
                'Nouvelle adresse d’abonnement générée. L’ancienne ne fonctionne plus : '
                . 'remplacez-la dans votre application de calendrier.'
            ),
            wp_get_referer() ?: home_url('/')
        ));
        exit;
    }

    private static function deny(): never
    {
        wp_die(
            'Cette adresse d’abonnement n’est plus valable. Récupérez la nouvelle depuis '
            . 'votre espace membre.',
            'Abonnement invalide',
            ['response' => 403]
        );
    }
}
