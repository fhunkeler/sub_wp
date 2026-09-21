<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Modes de règlement d'une adhésion, et l'adresse où l'on paie.
 *
 * Le club en écarte deux volontairement : l'espèce, qui ne laisse pas de trace
 * exploitable pour la trésorerie d'une association, et le virement, dont le
 * rapprochement coûtait plus de temps aux bénévoles qu'il n'en faisait gagner.
 * Ils restent nommés plus bas, parce que des règlements anciens les portent et
 * qu'un export ne doit pas afficher un code technique à leur place.
 *
 * Les adresses de paiement en ligne, elles, changent chaque saison : HelloAsso
 * ouvre une campagne d'adhésion et une boutique CE Orange par exercice, avec
 * une URL neuve à chaque fois. Les écrire en dur obligerait le club à demander
 * une livraison pour un lien — elles sont donc en réglage, les adresses de la
 * saison en cours servant seulement de valeurs de départ.
 */
final class PaymentMethods
{
    /** Ce que l'adhérent choisit au dépôt de son dossier. */
    private const OFFERED = [
        'helloasso' => 'HelloAsso',
        'cheque'    => 'Chèque',
        'ce_orange' => 'CE Orange',
    ];

    /** Modes retirés, conservés pour relire l'historique. */
    private const RETIRED = [
        'virement' => 'Virement',
        'especes'  => 'Espèces',
    ];

    private const LINKS_OPTION = 'subalcatel_payment_links';

    /**
     * Adresses de la saison 2026-2027, telles que le bureau les a ouvertes.
     *
     * Dès que le bureau enregistre l'onglet « Règlement », c'est sa saisie qui
     * fait foi — y compris quand elle vide un lien.
     */
    private const DEFAULT_LINKS = [
        'helloasso' => 'https://www.helloasso.com/associations/asac-tregor-subalcatel/adhesions/adhesion-2026-2027',
        'cheque'    => '',
        'ce_orange' => 'https://www.helloasso.com/associations/asac-tregor-subalcatel/boutiques/ce-orange-saison-2026-2027',
    ];

    /**
     * @return array<string, string>
     */
    public static function offered(): array
    {
        return self::OFFERED;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::OFFERED + self::RETIRED;
    }

    public static function isOffered(string $method): bool
    {
        return isset(self::OFFERED[$method]);
    }

    public static function label(string $method): string
    {
        return self::all()[$method] ?? $method;
    }

    /**
     * Les adresses de paiement en ligne, mode par mode.
     *
     * Un mode sans adresse — le chèque — rend une chaîne vide : l'absence de
     * lien est un état normal, pas une configuration manquante.
     *
     * @return array<string, string>
     */
    public static function links(): array
    {
        $stored = get_option(self::LINKS_OPTION, null);

        if (!is_array($stored)) {
            return self::DEFAULT_LINKS;
        }

        $links = [];

        foreach (array_keys(self::OFFERED) as $method) {
            $links[$method] = array_key_exists($method, $stored)
                ? (string) $stored[$method]
                : (self::DEFAULT_LINKS[$method] ?? '');
        }

        return $links;
    }

    public static function link(string $method): string
    {
        return self::links()[$method] ?? '';
    }

    /**
     * Ce que l'onglet « Règlement » propose de remplir, mode par mode.
     *
     * Le chèque y figure comme les autres : rien n'interdit au club d'ouvrir un
     * jour une page pour lui, et l'écarter d'avance obligerait à rouvrir le code
     * ce jour-là. L'aide dit ce qu'on attend, pour éviter qu'on colle l'adresse
     * de l'adhésion dans la case du CE.
     *
     * @return array<string, array{label: string, help: string}>
     */
    public static function linkFields(): array
    {
        return [
            'helloasso' => [
                'label' => 'HelloAsso — adhésion',
                'help'  => 'La page de la campagne d’adhésion de la saison. '
                    . 'Elle change d’adresse à chaque saison : reprenez celle que HelloAsso affiche.',
            ],
            'cheque'    => [
                'label' => 'Chèque',
                'help'  => 'Aucun paiement en ligne pour ce mode. Laissez vide, '
                    . 'sauf si le club ouvre un jour une page pour lui.',
            ],
            'ce_orange' => [
                'label' => 'HelloAsso — boutique CE Orange',
                'help'  => 'La boutique de la saison, qui porte la carte de niveau et l’assurance.',
            ],
        ];
    }

    /**
     * Enregistre les adresses saisies par le bureau.
     *
     * Une adresse mal recopiée est écartée plutôt que retenue : un lien de
     * paiement cassé se remarque trop tard, quand l'adhérent a déjà renoncé.
     * Seules http(s) passent — un `javascript:` collé par mégarde n'a rien à
     * faire dans un lien qu'on donne à cliquer.
     *
     * @param array<string, mixed> $links
     * @return array{saved: array<string, string>, rejected: list<string>}
     */
    public static function saveLinks(array $links): array
    {
        $clean    = [];
        $rejected = [];

        foreach (array_keys(self::OFFERED) as $method) {
            $url = trim((string) ($links[$method] ?? ''));

            if ($url === '') {
                $clean[$method] = '';
                continue;
            }

            if (!self::isWebUrl($url)) {
                $clean[$method] = '';
                $rejected[]     = $method;
                continue;
            }

            $clean[$method] = esc_url_raw($url);
        }

        update_option(self::LINKS_OPTION, $clean, false);

        return ['saved' => $clean, 'rejected' => $rejected];
    }

    private static function isWebUrl(string $url): bool
    {
        return in_array(wp_parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Ce que l'adhérent doit faire, une fois son dossier déposé.
     *
     * En texte : c'est la version qui part en courriel, où l'adresse doit se
     * lire telle quelle. La version cliquable est [instructionsHtml].
     */
    public static function instructions(string $method): string
    {
        $text = self::sentence($method);
        $link = self::link($method);

        return $link === '' ? $text : $text . ' ' . $link;
    }

    /**
     * La même consigne, l'adresse en lien cliquable.
     *
     * Rendue déjà échappée : les appelants l'insèrent telle quelle, sans
     * repasser par `esc_html` qui afficherait la balise au lieu du lien.
     */
    public static function instructionsHtml(string $method): string
    {
        $html = esc_html(self::sentence($method));
        $link = self::link($method);

        if ($link === '') {
            return $html;
        }

        return $html . sprintf(
            ' <a class="sub-payment-link" href="%s" rel="noopener" target="_blank">%s</a>',
            esc_url($link),
            esc_html(self::linkLabel($method))
        );
    }

    /** La consigne seule, sans adresse. */
    private static function sentence(string $method): string
    {
        return match ($method) {
            'helloasso' => 'Réglez en ligne depuis la page HelloAsso du club. '
                . 'Le bureau confirmera la réception.',
            'cheque'    => 'Établissez votre chèque à l’ordre du club et remettez-le '
                . 'à la trésorerie. Le bureau confirmera la réception.',
            'ce_orange' => 'Votre règlement passe par le comité d’entreprise Orange. '
                . 'Le bureau confirmera la réception auprès du CE.',
            default     => 'Le bureau confirmera la réception de votre règlement.',
        };
    }

    /** Ce que porte le lien : ce qu'on va payer, pas « cliquez ici ». */
    private static function linkLabel(string $method): string
    {
        return match ($method) {
            'helloasso' => 'Payer mon adhésion en ligne',
            'ce_orange' => 'Boutique CE Orange — carte de niveau et assurance',
            default     => 'Régler en ligne',
        };
    }
}
