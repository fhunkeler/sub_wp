<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Modes de règlement d'une adhésion, et ce qu'on dit à l'adhérent pour chacun.
 *
 * Le club en écarte deux volontairement : l'espèce, qui ne laisse pas de trace
 * exploitable pour la trésorerie d'une association, et le virement, dont le
 * rapprochement coûtait plus de temps aux bénévoles qu'il n'en faisait gagner.
 * Ils restent nommés plus bas, parce que des règlements anciens les portent et
 * qu'un export ne doit pas afficher un code technique à leur place.
 *
 * Les adresses de paiement en ligne, elles, ne sont pas ici : HelloAsso ouvre
 * une campagne d'adhésion et une boutique CE Orange **par saison**, comme le
 * club ouvre une campagne par saison. Elles vivent donc sur la campagne, avec
 * les tarifs et les options qu'elles encaissent — voir [CampaignRepository].
 * Cette classe ne fait que les mettre en forme, quand on les lui donne.
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
     * Ce que l'onglet « Règlement » d'une campagne propose de remplir.
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
                'help'  => 'La page de la campagne d’adhésion HelloAsso qui encaisse cette saison.',
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
     * Ramène une saisie à ce qu'on accepte d'enregistrer.
     *
     * Une adresse mal recopiée est écartée plutôt que retenue : un lien de
     * paiement cassé se remarque trop tard, quand l'adhérent a déjà renoncé.
     * Seules http(s) passent — un `javascript:` collé par mégarde n'a rien à
     * faire dans un lien qu'on donne à cliquer.
     *
     * Ne touche à rien : c'est l'appelant qui enregistre, sur la campagne.
     *
     * @param array<string, mixed> $links
     * @return array{links: array<string, string>, rejected: list<string>}
     */
    public static function sanitizeLinks(array $links): array
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

        return ['links' => $clean, 'rejected' => $rejected];
    }

    /**
     * Les adresses d'une campagne, lues depuis sa colonne `payment_links`.
     *
     * Un mode sans adresse rend une chaîne vide : l'absence de lien est un état
     * normal — le chèque n'en aura jamais, et une campagne en brouillon n'en a
     * pas encore.
     *
     * @return array<string, string>
     */
    public static function decodeLinks(mixed $json): array
    {
        $stored = is_string($json) && $json !== ''
            ? (array) (json_decode($json, true) ?: [])
            : [];

        $links = [];

        foreach (array_keys(self::OFFERED) as $method) {
            $links[$method] = (string) ($stored[$method] ?? '');
        }

        return $links;
    }

    /**
     * @param array<string, string> $links
     */
    public static function encodeLinks(array $links): string
    {
        return (string) wp_json_encode(array_filter($links, static fn (string $url): bool => $url !== ''));
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
    public static function instructions(string $method, string $link = ''): string
    {
        $text = self::sentence($method);

        return $link === '' ? $text : $text . ' ' . $link;
    }

    /**
     * La même consigne, l'adresse en lien cliquable.
     *
     * Rendue déjà échappée : les appelants l'insèrent telle quelle, sans
     * repasser par `esc_html` qui afficherait la balise au lieu du lien.
     */
    public static function instructionsHtml(string $method, string $link = ''): string
    {
        $html = esc_html(self::sentence($method));

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
