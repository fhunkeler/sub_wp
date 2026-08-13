<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Identity\ProfileFields;

/**
 * Liste des inscrits d'une sortie, pour le directeur de plongée.
 *
 * Ce que le DP emporte : niveau, téléphone, contact d'urgence, et la
 * **validité** des documents — jamais leur contenu. La règle du §6 ter vaut
 * aussi pour les exports : un fichier ne sort jamais d'ici.
 */
final class EventRosterExport extends Export
{
    public function key(): string
    {
        return 'event-roster';
    }

    public function label(): string
    {
        return 'Inscrits à une sortie';
    }

    public function description(): string
    {
        return 'Participants d’un événement, avec niveau, contacts et validité des documents.';
    }

    public function capability(): string
    {
        return 'sub_export_event';
    }

    public function columns(): array
    {
        return [
            '#', 'Nom', 'Prénom', 'Niveau', 'Téléphone',
            'Personne à prévenir', 'Documents', 'État', 'Véhicule',
        ];
    }

    public function filename(): string
    {
        $event = $this->event();
        $title = $event === null ? 'sortie' : sanitize_title((string) $event['title']);

        return sprintf('inscrits-%s-%s', $title, current_time('Y-m-d'));
    }

    public function rows(array $args = []): array
    {
        $eventId = (int) ($args['event_id'] ?? 0);

        if ($eventId === 0) {
            return [];
        }

        $rows = [];
        $i    = 1;

        foreach ((new EventService())->participants($eventId) as $person) {
            $userId = (int) $person['user_id'];

            $rows[] = [
                $i++,
                (string) ($person['display_name'] ?: ''),
                (string) get_user_meta($userId, 'first_name', true),
                (string) $person['level'],
                (string) ($person['phone'] ?: ''),
                (string) ($person['emergency'] ?: ''),
                // Une validité, pas un document.
                $person['medical_ok'] ? 'À jour' : 'À vérifier',
                $person['status'] === 'waiting' ? 'Liste d’attente' : 'Inscrit',
                ProfileFields::get($userId, 'vehicle_1'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function event(): ?array
    {
        $eventId = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;

        return $eventId === 0 ? null : (new EventService())->find($eventId);
    }
}
