<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Content\ClubDocuments;
use Subalcatel\Club\Content\DocumentDelivery;
use Subalcatel\Club\Content\DocumentLibrary;

/**
 * Espace documentaire : shortcode [subalcatel_documents_club].
 *
 * La page fonctionne sans connexion — les documents publics s'affichent pour
 * tout le monde. Un visiteur voit ce qui le concerne, et une invitation à se
 * connecter s'il y a davantage à voir.
 */
final class ClubDocumentsList
{
    public static function register(): void
    {
        add_shortcode('subalcatel_documents_club', [self::class, 'render']);
    }

    public static function render(): string
    {
        wp_enqueue_style(
            'subalcatel-membership',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/membership.css',
            [],
            \Subalcatel\Club\VERSION
        );

        $search = isset($_GET['doc_q']) ? sanitize_text_field(wp_unslash($_GET['doc_q'])) : '';
        $groups = (new DocumentLibrary())->browse(null, $search);
        $userId = get_current_user_id();

        ob_start();
        ?>
        <div class="sub-doclib">
            <form class="sub-doclib__search" method="get">
                <?php // Les autres paramètres de l'URL survivent à la recherche. ?>
                <?php foreach ($_GET as $name => $value) : ?>
                    <?php if ($name !== 'doc_q' && is_string($value)) : ?>
                        <input type="hidden" name="<?php echo esc_attr($name); ?>"
                               value="<?php echo esc_attr(wp_unslash($value)); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <label class="screen-reader-text" for="sub-doc-q">Rechercher un document</label>
                <input type="search" id="sub-doc-q" name="doc_q"
                       value="<?php echo esc_attr($search); ?>"
                       placeholder="Rechercher un document…">
                <button type="submit" class="sub-button sub-button--small">Rechercher</button>
                <?php if ($search !== '') : ?>
                    <a class="sub-button sub-button--ghost sub-button--small"
                       href="<?php echo esc_url(remove_query_arg('doc_q')); ?>">Tout afficher</a>
                <?php endif; ?>
            </form>

            <?php if ($groups === []) : ?>
                <div class="sub-notice">
                    <p><?php echo $search !== ''
                        ? 'Aucun document ne correspond à cette recherche.'
                        : 'Aucun document disponible pour le moment.'; ?></p>
                </div>
            <?php endif; ?>

            <?php foreach ($groups as $group) : ?>
                <section class="sub-doclib__group">
                    <h3><?php echo esc_html($group['term']?->name ?? 'Divers'); ?></h3>
                    <ul class="sub-doclib__list">
                        <?php foreach ($group['documents'] as $document) : ?>
                            <li class="sub-doclib__item">
                                <a class="sub-doclib__link"
                                   href="<?php echo esc_url(DocumentDelivery::url($document->ID)); ?>">
                                    <?php echo esc_html($document->post_title); ?>
                                </a>
                                <?php
                                $filename = (string) get_post_meta($document->ID, ClubDocuments::META_FILENAME, true);
                                $size     = (int) get_post_meta($document->ID, ClubDocuments::META_SIZE, true);
                                $access   = (string) get_post_meta($document->ID, ClubDocuments::META_ACCESS, true);
                                ?>
                                <span class="sub-doclib__meta">
                                    <?php echo esc_html(strtoupper((string) pathinfo($filename, PATHINFO_EXTENSION))); ?>
                                    <?php echo $size > 0 ? esc_html(' · ' . size_format($size)) : ''; ?>
                                    <?php if ($access !== ClubDocuments::ACCESS_PUBLIC) : ?>
                                        <span class="sub-doclib__lock" title="Réservé">🔒</span>
                                    <?php endif; ?>
                                </span>
                                <?php if (trim(wp_strip_all_tags($document->post_content)) !== '') : ?>
                                    <p class="sub-doclib__desc">
                                        <?php echo esc_html(wp_trim_words(wp_strip_all_tags($document->post_content), 30)); ?>
                                    </p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>

            <?php if ($userId === 0) : ?>
                <div class="sub-notice sub-notice--info">
                    <p>
                        D’autres documents sont réservés aux adhérents.
                        <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">Se connecter</a>
                        pour y accéder.
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
