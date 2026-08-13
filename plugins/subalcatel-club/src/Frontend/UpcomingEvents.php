<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Events\EventService;

/**
 * Aperçu public des prochaines sorties : [subalcatel_prochaines_sorties].
 *
 * À ne pas confondre avec `[subalcatel_agenda]`, qui gère les inscriptions et
 * reste réservé aux membres. Celui-ci ne fait que **montrer** : date, titre,
 * lieu. Un club qui n'affiche rien de sa vie à un visiteur n'attire personne,
 * et personne ne s'inscrit à ce qu'il ne voit pas.
 *
 * La séparation est nette et voulue : aucune éligibilité n'est calculée ici,
 * aucun nombre de places n'est exposé, aucun formulaire n'est rendu. Un
 * visiteur voit qu'il se passe des choses ; pour y prendre part, il se
 * connecte.
 */
final class UpcomingEvents
{
    public static function register(): void
    {
        add_shortcode('subalcatel_prochaines_sorties', [self::class, 'render']);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public static function render(array|string $atts = []): string
    {
        $atts = shortcode_atts([
            'limite' => '3',
            'titre'  => '',
        ], is_array($atts) ? $atts : []);

        $limit  = max(1, min(12, (int) $atts['limite']));
        $events = (new EventService())->upcoming($limit);

        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        ob_start();
        ?>
        <div class="sub-upcoming">
            <?php if ($atts['titre'] !== '') : ?>
                <h2 class="sub-upcoming__title"><?php echo esc_html($atts['titre']); ?></h2>
            <?php endif; ?>

            <?php if ($events === []) : ?>
                <p class="sub-upcoming__empty">
                    Aucune sortie programmée pour le moment. L’agenda se remplit au fil de la saison.
                </p>
            <?php else : ?>
                <ul class="sub-upcoming__list">
                    <?php foreach ($events as $event) : ?>
                        <?php $timestamp = strtotime((string) $event['starts_at']); ?>
                        <li class="sub-upcoming__item">
                            <span class="sub-upcoming__date" aria-hidden="true">
                                <span class="sub-upcoming__day"><?php echo esc_html(wp_date('j', $timestamp)); ?></span>
                                <span class="sub-upcoming__month"><?php echo esc_html(wp_date('M', $timestamp)); ?></span>
                            </span>
                            <span class="sub-upcoming__body">
                                <span class="sub-upcoming__name"><?php echo esc_html((string) $event['title']); ?></span>
                                <span class="sub-upcoming__meta">
                                    <?php echo esc_html(wp_date('l j F, H\hi', $timestamp)); ?>
                                    <?php if ((string) ($event['location'] ?? '') !== '') : ?>
                                        — <?php echo esc_html((string) $event['location']); ?>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="sub-upcoming__cta">
                <?php if (is_user_logged_in()) : ?>
                    <a href="<?php echo esc_url(Pages::url(Pages::AGENDA)); ?>">
                        Voir l’agenda complet et s’inscrire →
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url(wp_login_url(Pages::url(Pages::AGENDA))); ?>">
                        Se connecter pour s’inscrire →
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
