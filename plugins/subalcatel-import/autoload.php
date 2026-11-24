<?php
/**
 * Chargement des classes de la reprise.
 *
 * Isolé du fichier d'en-tête pour que les outils de `tools/` fonctionnent aussi
 * quand l'extension n'est pas activée : `wp eval-file` charge WordPress et
 * l'extension du club, et ce fichier suffit pour le reste. Une reprise se
 * lance depuis la ligne de commande, et n'a aucune raison de rester active
 * sur un site en service.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Subalcatel\\Import\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});
