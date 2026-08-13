<?php

declare(strict_types=1);

namespace Subalcatel\Club\Events;

/**
 * Génération de fichiers iCalendar (RFC 5545).
 *
 * Le format paraît trivial et ne l'est pas : un client de calendrier qui
 * n'aime pas le flux ne dit rien, il affiche un agenda vide. Quatre règles
 * qu'on ne peut pas approximer :
 *
 * 1. **Fins de ligne CRLF**, partout. Un `\n` seul suffit à faire rejeter.
 * 2. **Pliage à 75 octets** — octets, pas caractères : replier au milieu d'un
 *    « é » produit un fichier invalide.
 * 3. **Échappement** de `\`, `;`, `,` et des retours à la ligne.
 * 4. **UID stable** : s'il change, le client crée un doublon au lieu de mettre
 *    à jour l'événement existant.
 */
final class IcalWriter
{
    private const CRLF = "\r\n";

    /** Limite de la RFC, en octets, repli inclus. */
    private const FOLD_AT = 75;

    /**
     * @param list<array<string, string>> $events chaque entrée = propriétés d'un VEVENT
     */
    public static function calendar(string $name, string $description, array $events, ?int $ttlMinutes = null): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sub Alcatel//Club//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape($name),
            'X-WR-CALDESC:' . self::escape($description),
            'X-WR-TIMEZONE:' . wp_timezone_string(),
        ];

        if ($ttlMinutes !== null) {
            // Deux façons de dire la même chose : la norme, et ce que Google
            // Agenda lit réellement. Les deux coûtent une ligne.
            $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT' . $ttlMinutes . 'M';
            $lines[] = 'X-PUBLISHED-TTL:PT' . $ttlMinutes . 'M';
        }

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';

            foreach ($event as $property => $value) {
                $lines[] = $property . ':' . $value;
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode('', array_map([self::class, 'fold'], $lines));
    }

    /**
     * Construit les propriétés d'un événement du club.
     *
     * @param array<string, mixed> $event ligne de `sub_events`
     * @return array<string, string>
     */
    public static function event(array $event, string $url = ''): array
    {
        $start = self::utc((string) $event['starts_at']);

        // Sans heure de fin, on compte deux heures : un événement de durée
        // nulle s'affiche comme un point illisible dans la plupart des agendas.
        $end = (string) ($event['ends_at'] ?? '') !== ''
            ? self::utc((string) $event['ends_at'])
            : self::utc((string) $event['starts_at'], '+2 hours');

        $properties = [
            'UID'         => self::uid((int) $event['id']),
            'DTSTAMP'     => gmdate('Ymd\THis\Z'),
            'DTSTART'     => $start,
            'DTEND'       => $end,
            'SUMMARY'     => self::escape((string) $event['title']),
            'STATUS'      => ($event['status'] ?? '') === 'cancelled' ? 'CANCELLED' : 'CONFIRMED',
            'TRANSP'      => 'OPAQUE',
        ];

        if ((string) ($event['location'] ?? '') !== '') {
            $properties['LOCATION'] = self::escape((string) $event['location']);
        }

        $description = trim(wp_strip_all_tags((string) ($event['description'] ?? '')));

        if ($description !== '') {
            $properties['DESCRIPTION'] = self::escape($description);
        }

        if ($url !== '') {
            $properties['URL'] = self::escape($url);
        }

        return $properties;
    }

    /**
     * Identifiant stable et unique au site.
     *
     * L'hôte est inclus : deux installations du plugin ne doivent pas produire
     * le même UID, sinon l'agenda d'un membre inscrit aux deux clubs fusionne
     * des événements sans rapport.
     */
    public static function uid(int $eventId): string
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'subalcatel.local';

        return sprintf('sub-event-%d@%s', $eventId, $host);
    }

    /**
     * Convertit une date locale MySQL en horodatage UTC iCalendar.
     *
     * Les événements sont enregistrés dans le fuseau du site. Les envoyer tels
     * quels décale toutes les sorties d'une ou deux heures selon la saison —
     * un rendez-vous de plongée à 8 h affiché à 6 h.
     */
    public static function utc(string $localDateTime, string $modify = ''): string
    {
        try {
            $date = new \DateTimeImmutable($localDateTime, wp_timezone());
        } catch (\Exception) {
            return gmdate('Ymd\THis\Z');
        }

        if ($modify !== '') {
            $date = $date->modify($modify) ?: $date;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * Échappe une valeur textuelle.
     *
     * L'ordre compte : la contre-oblique d'abord, sinon on échapperait les
     * échappements qu'on vient d'ajouter.
     */
    public static function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value
        );
    }

    /**
     * Replie une ligne à 75 octets, la suite préfixée d'une espace.
     *
     * Le découpage se fait sur les octets mais ne doit jamais tomber au milieu
     * d'un caractère UTF-8 : on recule jusqu'au début du caractère courant.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_AT) {
            return $line . self::CRLF;
        }

        $folded    = '';
        $remaining = $line;
        $limit     = self::FOLD_AT;

        while (strlen($remaining) > $limit) {
            $cut = $limit;

            // 0b10xxxxxx marque un octet de continuation : tant qu'on est
            // dessus, la coupure tomberait au milieu d'un caractère.
            while ($cut > 1 && (ord($remaining[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            $folded   .= substr($remaining, 0, $cut) . self::CRLF . ' ';
            $remaining = substr($remaining, $cut);

            // Les lignes suivantes portent l'espace de continuation : il
            // compte dans les 75 octets.
            $limit = self::FOLD_AT - 1;
        }

        return $folded . $remaining . self::CRLF;
    }
}
