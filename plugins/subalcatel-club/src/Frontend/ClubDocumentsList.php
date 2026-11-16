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
 *
 * Les catégories forment une arborescence repliable : un `<details>` par
 * catégorie, qui marche sans JavaScript. Le script ne fait qu'ajouter « Tout
 * déplier / Tout replier » et ouvrir la branche visée par un lien du type
 * `…/documents/#doc-cat-niveau-1`.
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
        $tree   = (new DocumentLibrary())->tree(null, $search);
        $userId = get_current_user_id();

        // Une recherche ouvre tout : ses résultats ne doivent pas se cacher
        // derrière des rubriques fermées. Une seule rubrique s'ouvre d'office.
        $openAll = $search !== '';
        $openTop = $openAll || count($tree) === 1;

        ob_start();
        ?>
        <div class="sub-doclib">
            <?php echo ClubDocumentsContribute::render(); // déjà échappé ?>

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

            <?php if ($tree === []) : ?>
                <div class="sub-notice">
                    <p><?php echo $search !== ''
                        ? 'Aucun document ne correspond à cette recherche.'
                        : 'Aucun document disponible pour le moment.'; ?></p>
                </div>
            <?php else : ?>
                <div class="sub-doclib__tools" hidden>
                    <button type="button" class="sub-button sub-button--ghost sub-button--small"
                            data-doclib="open">Tout déplier</button>
                    <button type="button" class="sub-button sub-button--ghost sub-button--small"
                            data-doclib="close">Tout replier</button>
                </div>

                <div class="sub-doclib__tree">
                    <?php foreach ($tree as $node) : ?>
                        <?php self::renderNode($node, 0, $openTop, $openAll); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($userId === 0) : ?>
                <div class="sub-notice sub-notice--info">
                    <p>
                        D’autres documents sont réservés aux adhérents.
                        <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">Se connecter</a>
                        pour y accéder.
                    </p>
                </div>
            <?php endif; ?>
        <script>
        (function () {
            var root = document.currentScript && document.currentScript.parentElement;
            if (!root || !root.classList.contains('sub-doclib')) { return; }
            var all = root.querySelectorAll('details.sub-doclib__group');
            var tools = root.querySelector('.sub-doclib__tools');
            if (tools && all.length > 1) {
                tools.hidden = false;
                tools.addEventListener('click', function (e) {
                    var action = e.target.getAttribute && e.target.getAttribute('data-doclib');
                    if (!action) { return; }
                    all.forEach(function (d) { d.open = action === 'open'; });
                });
            }
            // Un lien vers une rubrique ouvre la rubrique et toutes ses parentes.
            function reveal() {
                var id = decodeURIComponent(location.hash.slice(1));
                var target = id && document.getElementById(id);
                if (!target || !root.contains(target)) { return; }
                for (var d = target; d && d !== root; d = d.parentElement) {
                    if (d.tagName === 'DETAILS') { d.open = true; }
                }
                target.scrollIntoView();
            }
            reveal();
            window.addEventListener('hashchange', reveal);
        })();
        </script>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Une catégorie : son titre repliable, ses documents, puis ses sous-catégories.
     *
     * @param array{term: \WP_Term|null, documents: list<\WP_Post>, children: list<array<string, mixed>>, count: int} $node
     */
    private static function renderNode(array $node, int $depth, bool $open, bool $openAll): void
    {
        $term  = $node['term'];
        $name  = $term?->name ?? 'Divers';
        $id    = 'doc-cat-' . ($term?->slug ?? 'divers');
        $count = (int) $node['count'];
        ?>
        <details class="sub-doclib__group sub-doclib__group--depth-<?php echo (int) min($depth, 3); ?>"
                 id="<?php echo esc_attr($id); ?>"<?php echo $open ? ' open' : ''; ?>>
            <summary class="sub-doclib__summary">
                <span class="sub-doclib__name"><?php echo esc_html($name); ?></span>
                <span class="sub-doclib__count"><?php
                    echo esc_html($count === 1 ? '1 document' : $count . ' documents');
                ?></span>
            </summary>

            <div class="sub-doclib__body">
                <?php if ($node['documents'] !== []) : ?>
                    <ul class="sub-doclib__list">
                        <?php foreach ($node['documents'] as $document) : ?>
                            <?php self::renderDocument($document); ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php foreach ($node['children'] as $child) : ?>
                    <?php self::renderNode($child, $depth + 1, $openAll, $openAll); ?>
                <?php endforeach; ?>
            </div>
        </details>
        <?php
    }

    private static function renderDocument(\WP_Post $document): void
    {
        $filename = (string) get_post_meta($document->ID, ClubDocuments::META_FILENAME, true);
        $size     = (int) get_post_meta($document->ID, ClubDocuments::META_SIZE, true);
        $access   = (string) get_post_meta($document->ID, ClubDocuments::META_ACCESS, true);
        ?>
        <li class="sub-doclib__item">
            <a class="sub-doclib__link"
               href="<?php echo esc_url(DocumentDelivery::url($document->ID)); ?>"
               target="_blank" rel="noopener">
                <?php echo esc_html($document->post_title); ?>
            </a>
            <span class="sub-doclib__meta">
                <?php echo esc_html(strtoupper((string) pathinfo($filename, PATHINFO_EXTENSION))); ?>
                <?php echo $size > 0 ? esc_html(' · ' . size_format($size)) : ''; ?>
                <?php if ($access !== '' && $access !== ClubDocuments::ACCESS_PUBLIC) : ?>
                    <span class="sub-doclib__lock" title="Réservé">🔒</span>
                <?php endif; ?>
            </span>
            <?php if (trim(wp_strip_all_tags($document->post_content)) !== '') : ?>
                <p class="sub-doclib__desc">
                    <?php echo esc_html(wp_trim_words(wp_strip_all_tags($document->post_content), 30)); ?>
                </p>
            <?php endif; ?>
        </li>
        <?php
    }
}
