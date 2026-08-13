<?php
/**
 * Plugin Name:  Sub Alcatel — Gestion du club
 * Description:  Adhésions, événements, documents et droits du club de plongée Sub Alcatel.
 * Version:      0.14.0
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Author:       Sub Alcatel
 * Update URI:   https://github.com/fhunkeler/sub_wp
 * Text Domain:  subalcatel-club
 * Domain Path:  /languages
 */

declare(strict_types=1);

namespace Subalcatel\Club;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION     = '0.14.0';
const PLUGIN_FILE = __FILE__;

define(__NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path(__FILE__));
define(__NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Autoloader PSR-4 minimal.
 *
 * Pas de Composer : une dizaine de lignes suffisent, et le plugin reste
 * installable en copiant le dossier — ce qui compte pour des bénévoles.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path     = PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

add_action('plugins_loaded', [Plugin::class, 'boot']);
