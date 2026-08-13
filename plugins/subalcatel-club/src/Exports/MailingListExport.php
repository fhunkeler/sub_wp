<?php

declare(strict_types=1);

namespace Subalcatel\Club\Exports;

use Subalcatel\Club\Communication\MailingLists;
use Subalcatel\Club\Communication\Subscriptions;

/**
 * Destinataires d'une liste de diffusion, pour l'outil d'envoi.
 *
 * Ne sort que les **abonnés**, jamais l'effectif complet : exporter la liste
 * entière puis « faire attention » au moment de l'envoi est la façon dont on
 * écrit à des gens qui s'étaient désinscrits.
 *
 * La date de consentement accompagne chaque ligne — c'est elle qui rend l'envoi
 * défendable, et elle doit survivre au passage dans un outil tiers.
 */
final class MailingListExport extends Export
{
    public function key(): string
    {
        return 'mailing-list';
    }

    public function label(): string
    {
        return 'Destinataires d’une liste';
    }

    public function description(): string
    {
        return 'Abonnés d’une liste de diffusion, avec leur date de consentement.';
    }

    public function capability(): string
    {
        return 'sub_manage_content';
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return ['Adresse', 'Prénom', 'Nom', 'Liste', 'Consentement le', 'Origine'];
    }

    /**
     * @param array<string, mixed> $args
     * @return list<list<string|int|float>>
     */
    public function rows(array $args = []): array
    {
        $slug = (string) ($args['list'] ?? '');
        $list = MailingLists::find($slug);

        if ($list === null) {
            return [];
        }

        $rows = [];

        foreach (MailingLists::recipients($slug) as $recipient) {
            $user  = get_userdata($recipient['id']);
            $state = Subscriptions::stateOf($recipient['id']);

            $rows[] = [
                $recipient['email'],
                (string) $user->first_name,
                (string) $user->last_name,
                (string) $list['label'],
                $state['date'],
                $state['source'],
            ];
        }

        return $rows;
    }

    public function filename(): string
    {
        return 'liste-diffusion-' . current_time('Y-m-d');
    }
}
