<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

/**
 * Tout ce qui part vers les membres, sous une seule entrée.
 *
 * Les listes de diffusion et les modèles de courriel répondaient à la même
 * question — « qui reçoit quoi ? » — depuis deux entrées de menu éloignées
 * l'une de l'autre. Elles sont ici réunies ; le code, lui, reste chez
 * `MailingListsScreen` et `NotificationsScreen`.
 */
final class CommunicationScreen
{
    public const SLUG = 'subalcatel-communication';

    /** @var list<string> */
    public const CAPABILITIES = ['sub_manage_content', 'sub_manage_memberships'];

    public static function render(): void
    {
        AdminUi::tabbedScreen(self::SLUG, 'Communication', [
            'listes'  => [
                'label'  => 'Listes de diffusion',
                'cap'    => 'sub_manage_content',
                'render' => [MailingListsScreen::class, 'renderTab'],
            ],
            'modeles' => [
                'label'  => 'Modèles de courriel',
                'cap'    => 'sub_manage_memberships',
                'render' => [NotificationsScreen::class, 'renderTemplates'],
            ],
            'journal' => [
                'label'  => 'Journal des envois',
                'cap'    => 'sub_manage_memberships',
                'render' => [NotificationsScreen::class, 'renderLog'],
            ],
        ]);
    }
}
