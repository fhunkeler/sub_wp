<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

use Subalcatel\Club\Setup\Updater;

/**
 * Pourquoi le site se met à jour, ou ne se met pas à jour.
 *
 * L'extension se met à jour depuis les releases du dépôt, et l'appel qui les
 * lit échoue en silence — par choix : une extension qui hurle dans
 * l'administration parce que GitHub tousse finit par être désactivée.
 *
 * Le silence a un prix, payé le 15/09/2026. Un site du club ne voyait plus
 * aucune mise à jour, et il a fallu déposer un fichier de diagnostic sur
 * l'hébergement pour découvrir la cause : le quota anonyme de GitHub, épuisé.
 * Il se compte par adresse IP, et sur un hébergement mutualisé cette adresse
 * est partagée avec des inconnus — le club n'y était pour rien et ne pouvait
 * rien y voir.
 *
 * D'où cet écran. Il ne règle rien tout seul ; il dit ce qui se passe, ce qui
 * suffit à savoir quoi faire.
 */
final class UpdatesScreen
{
    public const TAB = 'updates';

    public static function register(): void
    {
        add_action('admin_post_sub_updates_refresh', [self::class, 'handleRefresh']);
    }

    public static function renderTab(): void
    {
        AdminUi::requireCap('manage_options');

        $etat    = Updater::etat();
        $plugin  = Updater::derniere(Updater::PLUGIN_SLUG);
        $theme   = Updater::derniere(Updater::THEME_SLUG);
        $installe = self::installe();
        ?>
        <p class="description">
            L’extension et le thème se mettent à jour depuis les versions publiées sur le
            dépôt du club, sans passer par wordpress.org. Cet écran montre ce que le site
            voit — et, quand il ne voit rien, pourquoi.
        </p>

        <?php self::renderAlerte($etat); ?>

        <h2>Ce qui est installé</h2>
        <table class="widefat striped sub-updates">
            <tbody>
                <?php
                self::ligne('Extension', sprintf(
                    '%s — version %s',
                    Updater::PLUGIN_FICHIER,
                    $installe['plugin'] ?? 'introuvable'
                ), isset($installe['plugin']));

                self::ligne('Thème', sprintf('%s — version %s', $installe['theme_nom'], $installe['theme']));
                ?>
            </tbody>
        </table>

        <h2 style="margin-top:28px;">Ce qui est publié</h2>
        <table class="widefat striped sub-updates">
            <tbody>
                <?php
                self::renderPublie('Extension', $plugin, $installe['plugin'] ?? null);
                self::renderPublie('Thème', $theme, $installe['theme']);
                ?>
            </tbody>
        </table>

        <h2 style="margin-top:28px;">Le dernier appel au dépôt</h2>
        <table class="widefat striped sub-updates">
            <tbody>
                <?php self::renderAppel($etat); ?>
            </tbody>
        </table>

        <p style="margin-top:16px;">
            <?php AdminUi::actionButton(
                'sub_updates_refresh',
                [],
                'Refaire l’appel maintenant',
                'button button-primary'
            ); ?>
            <span class="description" style="margin-left:8px;">
                Vide le cache et réinterroge le dépôt. C’est aussi ce que fait
                « Vérifier à nouveau » depuis l’écran des mises à jour de WordPress.
            </span>
        </p>
        <?php
    }

    /**
     * Le bandeau qui dit quoi faire, quand il y a quelque chose à faire.
     *
     * @param array<string, mixed> $etat
     */
    private static function renderAlerte(array $etat): void
    {
        $quota   = $etat['remaining'];
        $epuise  = $quota !== null && (int) $quota === 0;
        $echec   = $etat['error'] !== '' || ((int) $etat['code'] !== 0 && (int) $etat['code'] !== 200);

        if (!$epuise && !$echec) {
            return;
        }

        echo '<div class="notice notice-error inline" style="margin:16px 0;padding:8px 12px;">';

        if ($etat['error'] !== '') {
            printf(
                '<p><strong>Le site n’a pas pu joindre le dépôt.</strong> %s</p>'
                . '<p>Le plus souvent, l’hébergeur bloque les appels sortants. '
                . 'C’est à lui qu’il faut demander l’ouverture vers <code>api.github.com</code>.</p>',
                esc_html((string) $etat['message'])
            );
        } elseif ($epuise) {
            // Le cas vécu : rien n'est cassé, le voisinage a consommé le quota.
            echo '<p><strong>Le quota d’appels à GitHub est épuisé.</strong> Sans jeton, GitHub '
                . 'accorde 60 appels par heure <em>et par adresse IP</em> — sur un hébergement '
                . 'mutualisé, cette adresse est partagée avec d’autres sites. Rien n’est cassé, '
                . 'et le club n’y est pour rien.</p>';
            self::renderRemede();
        } else {
            printf(
                '<p><strong>Le dépôt a répondu %s.</strong> %s</p>',
                esc_html('HTTP ' . (int) $etat['code']),
                esc_html((string) $etat['message'])
            );
            self::renderRemede();
        }

        echo '</div>';
    }

    private static function renderRemede(): void
    {
        if (Updater::jetonPose()) {
            echo '<p>Un jeton est pourtant défini : vérifiez qu’il n’a pas expiré.</p>';

            return;
        }

        echo '<p>Un jeton GitHub porte la limite à 5 000 appels par heure. Il se crée sur '
            . 'github.com (<em>Settings → Developer settings → Personal access tokens</em>), '
            . 'en lecture seule sur les dépôts publics, sans aucune permission de compte, '
            . 'puis se pose dans <code>wp-config.php</code> :</p>'
            . '<p><code>define(\'SUBALCATEL_GITHUB_TOKEN\', \'…\');</code></p>';
    }

    /**
     * @param array<string, mixed>|null $release
     */
    private static function renderPublie(string $quoi, ?array $release, ?string $installee): void
    {
        if ($release === null) {
            self::ligne($quoi, 'aucune version publiée trouvée', false);

            return;
        }

        $version = (string) $release['version'];
        $neuve   = $installee !== null && version_compare($version, $installee, '>');

        self::ligne(
            $quoi,
            sprintf(
                '%s%s%s',
                $version,
                $release['date'] !== '' ? ' — publiée le ' . AdminUi::frDate(substr((string) $release['date'], 0, 10)) : '',
                $neuve ? ' — plus récente que l’installée' : ' — déjà installée'
            ),
            true,
            $neuve ? sprintf('<a href="%s">voir les notes</a>', esc_url((string) $release['url'])) : ''
        );
    }

    /**
     * @param array<string, mixed> $etat
     */
    private static function renderAppel(array $etat): void
    {
        if ((int) $etat['at'] === 0) {
            self::ligne('Dernier appel', 'aucun appel connu — utilisez le bouton ci-dessous', null);

            return;
        }

        self::ligne('Quand', sprintf(
            'le %s à %s',
            wp_date('d/m/Y', (int) $etat['at']),
            wp_date('H:i', (int) $etat['at'])
        ));

        if ($etat['error'] !== '') {
            self::ligne('Résultat', 'échec — ' . $etat['error'], false);
            self::ligne('Message', (string) $etat['message'], false);
        } else {
            $code = (int) $etat['code'];
            self::ligne('Résultat', 'HTTP ' . $code, $code === 200);

            if ($etat['message'] !== '') {
                self::ligne('Message', (string) $etat['message'], false);
            }
        }

        if ($etat['limit'] !== null) {
            $restant = (int) $etat['remaining'];

            self::ligne(
                'Quota d’appels',
                sprintf('%d restant(s) sur %d', $restant, (int) $etat['limit']),
                $restant > 0
            );
        }

        if ($etat['reset'] !== null) {
            self::ligne('Quota rétabli à', wp_date('H:i', (int) $etat['reset']));
        }

        self::ligne('Versions lues', sprintf('%d', (int) $etat['count']), (int) $etat['count'] > 0);

        // Jamais la valeur : un secret affiché dans une administration finit
        // dans une capture d'écran.
        self::ligne(
            'Jeton GitHub',
            Updater::jetonPose() ? 'défini dans wp-config.php' : 'absent — limite de 60 appels par heure et par IP',
            Updater::jetonPose()
        );
    }

    private static function ligne(string $libelle, string $valeur, ?bool $bon = null, string $suffixe = ''): void
    {
        $couleur = match ($bon) {
            true  => 'color:#17795e;font-weight:600;',
            false => 'color:#b82a1e;font-weight:600;',
            null  => '',
        };

        printf(
            '<tr><th scope="row" style="width:220px;text-align:left;">%s</th>'
            . '<td><span style="%s">%s</span> %s</td></tr>',
            esc_html($libelle),
            esc_attr($couleur),
            esc_html($valeur),
            wp_kses_post($suffixe)
        );
    }

    /**
     * @return array{plugin?: string, theme: string, theme_nom: string}
     */
    private static function installe(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $extensions = get_plugins();
        $theme      = wp_get_theme();

        $etat = [
            'theme'     => (string) $theme->get('Version'),
            'theme_nom' => (string) $theme->get('Name'),
        ];

        // Le chemin exact compte : l'offre de mise à jour n'est faite que pour
        // lui. Une extension installée sous un autre nom de dossier ne se met
        // jamais à jour, et rien ne le signale ailleurs qu'ici.
        if (isset($extensions[Updater::PLUGIN_FICHIER])) {
            $etat['plugin'] = (string) $extensions[Updater::PLUGIN_FICHIER]['Version'];
        }

        return $etat;
    }

    public static function handleRefresh(): void
    {
        check_admin_referer('sub_updates_refresh');
        AdminUi::requireCap('manage_options');

        $etat = Updater::rafraichir();

        // Le résultat est déjà à l'écran juste après ; le message dit seulement
        // si l'appel est passé, pour qui ne lit pas le tableau.
        $message = $etat['error'] !== ''
            ? 'Appel échoué : ' . $etat['error']
            : ((int) $etat['code'] === 200
                ? sprintf('Dépôt interrogé : %d version(s) lue(s).', (int) $etat['count'])
                : sprintf('Le dépôt a répondu HTTP %d.', (int) $etat['code']));

        AdminUi::redirect(
            SettingsScreen::SLUG,
            $message,
            $etat['error'] !== '' || (int) $etat['code'] !== 200,
            ['tab' => self::TAB]
        );
    }
}
