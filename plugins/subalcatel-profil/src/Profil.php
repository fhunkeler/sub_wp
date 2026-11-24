<?php

declare(strict_types=1);

namespace Subalcatel\Profil;

/**
 * Le profil de Sub Alcatel : ce que le club règle autrement que les autres.
 *
 * L'extension du club est le produit, commun à tous les clubs. Ce qui n'est
 * vrai que pour Sub Alcatel vit ici, et s'y branche par les filtres qu'elle
 * expose. Un autre club aurait son propre profil — ou aucun.
 */
final class Profil
{
    /** {@see \Subalcatel\Club\Membership\PaymentMethods::FILTER} */
    public const PAYMENT_METHODS_FILTER = 'subalcatel_payment_methods';

    /** {@see \Subalcatel\Club\Setup\Updater::PLUGINS_FILTER} */
    public const UPDATER_FILTER = 'subalcatel_updater_plugins';

    public const SLUG = 'subalcatel-profil';

    public static function register(): void
    {
        add_filter(self::PAYMENT_METHODS_FILTER, [CeOrange::class, 'addTo']);
        add_filter(self::UPDATER_FILTER, [self::class, 'followUpdates']);
    }

    /**
     * Ce profil se met à jour comme l'extension du club, depuis les releases
     * du même dépôt, sous les balises `profil-X.Y.Z`.
     *
     * @param array<string, array<string, string>> $plugins
     * @return array<string, array<string, string>>
     */
    public static function followUpdates(array $plugins): array
    {
        $plugins[plugin_basename(PLUGIN_FILE)] = [
            'slug'    => self::SLUG,
            'prefixe' => 'profil-',
            'archive' => 'subalcatel-profil',
        ];

        return $plugins;
    }
}
