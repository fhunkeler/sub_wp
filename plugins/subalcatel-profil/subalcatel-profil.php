<?php
/**
 * Plugin Name:  Sub Alcatel — Profil du club
 * Description:  Ce qui est propre à Sub Alcatel et non au produit : le règlement par le CE Orange et sa prise en charge. S'appuie sur l'extension « Sub Alcatel — Gestion du club ».
 * Version:      1.0.0
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Requires Plugins: subalcatel-club
 * Author:       Sub Alcatel
 * Update URI:   https://github.com/fhunkeler/sub_wp
 * Text Domain:  subalcatel-profil
 */

declare(strict_types=1);

namespace Subalcatel\Profil;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION = '1.0.0';
const PLUGIN_FILE = __FILE__;

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});

/*
 * Tout passe par des filtres de l'extension du club, sous leur nom en toutes
 * lettres et non par ses constantes : ce profil doit pouvoir être installé
 * **avant** la version du cœur qui le lit. Sur un cœur plus ancien, ces filtres
 * ne sont jamais appelés, et le profil ne fait rien — c'est ce qui permet de
 * l'installer d'abord, puis de mettre le cœur à jour, sans coupure.
 */
Profil::register();
