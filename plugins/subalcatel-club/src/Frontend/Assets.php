<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

/**
 * Mise en file de la feuille de l'espace membre, au bon moment.
 *
 * Onze écrans appelaient `wp_enqueue_style()` depuis le rendu de leur
 * shortcode. Or un shortcode s'exécute pendant `the_content`, donc **après**
 * `wp_head` : WordPress reporte alors la feuille en pied de page. La page
 * s'affichait brute, puis se restylait — un décalage visible à chaque
 * chargement de l'espace membre.
 *
 * On repère donc les écrans concernés dès `wp_enqueue_scripts`, en lisant le
 * contenu de la page demandée. Les appels faits pendant le rendu restent en
 * place : `wp_enqueue_style()` est idempotent, et ils couvrent les cas que
 * cette classe ne voit pas : un shortcode posé dans un widget, un bloc
 * réutilisable, ou — cas réel — écrit directement dans un gabarit de blocs,
 * comme `[subalcatel_prochaines_sorties]` dans front-page.html. Ces pages-là
 * gardent donc leur feuille en pied de page ; ce sont aussi celles où elle pèse
 * le moins.
 */
final class Assets
{
    /**
     * Shortcodes dont le rendu a besoin de membership.css.
     *
     * @var list<string>
     */
    private const SHORTCODES = [
        'subalcatel_adhesion',
        'subalcatel_agenda',
        'subalcatel_calendrier',
        'subalcatel_connexion',
        'subalcatel_creer_compte',
        'subalcatel_creer_sortie',
        'subalcatel_documents',
        'subalcatel_documents_club',
        'subalcatel_espace_membre',
        'subalcatel_mes_inscriptions',
        'subalcatel_mes_sorties_organisees',
        'subalcatel_mon_adhesion',
        'subalcatel_prochaines_sorties',
        'subalcatel_profil',
        'subalcatel_tarifs',
    ];

    public static function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(): void
    {
        if (!self::needed()) {
            return;
        }

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );
    }

    /**
     * La page demandée affiche-t-elle un écran du club ?
     */
    private static function needed(): bool
    {
        if (!is_singular()) {
            return false;
        }

        $post = get_post();

        if (!$post instanceof \WP_Post) {
            return false;
        }

        foreach (self::SHORTCODES as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
        }

        return false;
    }
}
