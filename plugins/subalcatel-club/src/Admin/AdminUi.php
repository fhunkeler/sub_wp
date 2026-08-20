<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

/**
 * Briques communes aux écrans du bureau.
 *
 * Rien de métier ici : uniquement de l'affichage, des redirections et des
 * contrôles d'accès factorisés.
 */
final class AdminUi
{
    public static function requireCap(string $capability): void
    {
        if (!current_user_can($capability)) {
            wp_die('Accès refusé.', 403);
        }
    }

    /**
     * Message de confirmation ou d'erreur après une action.
     */
    public static function flash(): void
    {
        foreach (['sub_done' => 'notice-success', 'sub_error' => 'notice-error'] as $key => $class) {
            if (isset($_GET[$key])) {
                printf(
                    '<div class="notice %s is-dismissible"><p>%s</p></div>',
                    esc_attr($class),
                    esc_html(sanitize_text_field(wp_unslash((string) $_GET[$key])))
                );
            }
        }
    }

    /**
     * @param array<string, string|int> $extraArgs
     */
    public static function redirect(
        string $page,
        string $message,
        bool $isError = false,
        array $extraArgs = [],
    ): never {
        wp_safe_redirect(add_query_arg(
            array_merge(
                ['page' => $page],
                $extraArgs,
                [$isError ? 'sub_error' : 'sub_done' => rawurlencode($message)]
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Bouton déclenchant une action POST — jamais un lien.
     *
     * Une action qui modifie l'état ne doit pas être atteignable par un GET :
     * un préchargeur de navigateur ou un robot d'indexation la déclencherait.
     *
     * @param array<string, string|int> $fields
     */
    public static function actionButton(
        string $action,
        array $fields,
        string $label,
        string $class = 'button',
        string $confirm = '',
    ): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="display:inline;"
              <?php if ($confirm !== '') : ?>
                  onsubmit="return confirm(<?php echo esc_attr(wp_json_encode($confirm)); ?>);"
              <?php endif; ?>>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php foreach ($fields as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr((string) $name); ?>"
                       value="<?php echo esc_attr((string) $value); ?>">
            <?php endforeach; ?>
            <?php wp_nonce_field($action); ?>
            <button class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    /**
     * Pastille de compteur, dans un libellé de menu ou d'onglet.
     *
     * C'est elle qui fait traiter une file d'attente : sans compteur, personne
     * n'ouvre un écran dont il ignore s'il contient quelque chose.
     *
     * `$label` sert aux compteurs qui ne sont pas exacts : une file plafonnée
     * s'annonce « 10 + », faute de savoir dire combien au-delà.
     */
    public static function countBubble(int $count, string $label = ''): string
    {
        if ($count <= 0) {
            return '';
        }

        return sprintf(
            ' <span class="update-plugins count-%d"><span class="update-count">%s</span></span>',
            $count,
            esc_html($label !== '' ? $label : (string) $count)
        );
    }

    /**
     * Écran à onglets.
     *
     * Regrouper plusieurs écrans sous une entrée de menu ne vaut que si les
     * droits suivent : un onglet dont la personne n'a pas la capacité n'est pas
     * affiché, et l'arrivée se fait sur le premier onglet qui lui reste. On ne
     * montre jamais une entrée de menu qui ouvre sur un refus d'accès.
     *
     * @param array<string, array{label: string, cap?: string, count?: int, render: callable}> $tabs
     */
    public static function tabbedScreen(string $page, string $title, array $tabs): void
    {
        $tabs = array_filter(
            $tabs,
            static fn (array $tab): bool => !isset($tab['cap']) || current_user_can($tab['cap'])
        );

        if ($tabs === []) {
            wp_die('Accès refusé.', 403);
        }

        self::enqueue();

        $requested = sanitize_key(wp_unslash((string) ($_GET['tab'] ?? '')));
        $current   = isset($tabs[$requested]) ? $requested : (string) array_key_first($tabs);

        printf('<div class="wrap sub-admin"><h1>%s</h1>', esc_html($title));
        self::flash();

        // Un seul onglet ne fait pas une navigation : on n'affiche alors que
        // son contenu. C'est le cas d'un trésorier sur « Membres ».
        if (count($tabs) > 1) {
            echo '<nav class="nav-tab-wrapper" style="margin-top:16px;">';

            foreach ($tabs as $key => $tab) {
                printf(
                    '<a class="nav-tab %s" href="%s">%s%s</a>',
                    $key === $current ? 'nav-tab-active' : '',
                    esc_url(self::tabUrl($page, (string) $key)),
                    esc_html($tab['label']),
                    self::countBubble((int) ($tab['count'] ?? 0))
                );
            }

            echo '</nav>';
        }

        echo '<div style="margin-top:20px;">';
        ($tabs[$current]['render'])();
        echo '</div></div>';
    }

    public static function tabUrl(string $page, string $tab = ''): string
    {
        return admin_url('admin.php?page=' . $page . ($tab === '' ? '' : '&tab=' . $tab));
    }

    public static function statusBadge(string $status): string
    {
        $map = [
            'draft'             => ['Brouillon', '#c4d3e3', '#142f52'],
            'inactive'          => ['Inactive', '#566b84', '#fff'],
            'open'              => ['Ouverte', '#17795e', '#fff'],
            'closed'            => ['Fermée', '#566b84', '#fff'],
            'awaiting_payment'  => ['En attente de paiement', '#f2c14e', '#142f52'],
            'payment_confirmed' => ['Paiement confirmé', '#1d5480', '#fff'],
            'active'            => ['Active', '#17795e', '#fff'],
            'refused'           => ['Refusée', '#b82a1e', '#fff'],
            'cancelled'         => ['Annulée', '#566b84', '#fff'],
        ];

        [$label, $bg, $fg] = $map[$status] ?? [$status, '#c4d3e3', '#142f52'];

        // Jamais la couleur seule : le libellé porte l'information (WCAG 1.4.1).
        return sprintf(
            '<span class="sub-badge" style="background:%s;color:%s;">%s</span>',
            esc_attr($bg),
            esc_attr($fg),
            esc_html($label)
        );
    }

    public static function frDate(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '' || $isoDate === '0000-00-00') {
            return '—';
        }

        $ts = strtotime($isoDate);

        return $ts === false ? $isoDate : wp_date('d/m/Y', $ts);
    }

    /**
     * Date validée, ou chaîne vide si la saisie n'est pas exploitable.
     */
    public static function date(mixed $raw): string
    {
        $value = sanitize_text_field(wp_unslash((string) $raw));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * Montant en euros, virgule ou point acceptés, symbole € toléré.
     *
     * Les fee_values du Joomla mélangeaient « 25.00€ », « 0,00€ » et « 36€ » :
     * on reste tolérant en lecture, strict à l'écriture.
     */
    public static function amount(mixed $raw): float
    {
        $value = str_replace([' ', ' ', '€'], '', (string) $raw);
        $value = str_replace(',', '.', $value);

        return round((float) $value, 2);
    }

    public static function euro(float $amount, bool $signed = false): string
    {
        $prefix = $signed && $amount > 0 ? '+' : '';

        return $prefix . number_format($amount, 2, ',', ' ') . ' €';
    }

    public static function enqueue(): void
    {
        wp_enqueue_style(
            'subalcatel-admin',
            \Subalcatel\Club\PLUGIN_URL . 'assets/css/admin.css',
            [],
            \Subalcatel\Club\VERSION
        );

        wp_enqueue_script(
            'subalcatel-admin',
            \Subalcatel\Club\PLUGIN_URL . 'assets/js/admin.js',
            [],
            \Subalcatel\Club\VERSION,
            true
        );
    }
}
