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
 * une campagne d'adhésion **par saison**, comme le club ouvre une campagne par
 * saison. Elles vivent donc sur la campagne, avec les tarifs et les options
 * qu'elles encaissent — voir [CampaignRepository]. Cette classe ne fait que
 * les mettre en forme, quand on les lui donne.
 *
 * La liste est ouverte par le filtre {@see self::FILTER} : un club y ajoute ses
 * propres modes — Sub Alcatel, le règlement par le CE Orange, depuis son
 * extension de profil. Un mode peut alors déclarer un **tiers payeur**, qui
 * prend en charge une partie du montant ({@see ThirdPartyShare}).
 */
final class PaymentMethods
{
    /**
     * Filtre : la liste des modes de règlement, clé technique → définition.
     *
     *     add_filter('subalcatel_payment_methods', static fn (array $modes): array => $modes + [
     *         'ce_entreprise' => [
     *             'label'       => 'CE de l’entreprise',     // obligatoire
     *             'offered'     => true,                      // proposé au dépôt (défaut : true)
     *             'sentence'    => 'Votre règlement passe…',  // consigne après dépôt
     *             'link'        => ['label' => '…', 'help' => '…'], // une adresse par campagne
     *             'link_label'  => 'Boutique du CE',          // texte du lien
     *             'third_party' => [                          // voir ThirdPartyShare
     *                 'payer'          => 'Le CE',
     *                 'by'             => 'par le CE',
     *                 'member_options' => ['carte_niveau'],
     *                 'member_label'   => 'carte de niveau',
     *                 'member_where'   => 'dans la boutique du CE',
     *             ],
     *         ],
     *     ]);
     *
     * L'ordre du tableau est celui du formulaire.
     */
    public const FILTER = 'subalcatel_payment_methods';

    /**
     * Les modes que le cœur connaît. Les deux derniers sont retirés : on ne les
     * propose plus, mais des règlements anciens les portent, et un export ne
     * doit pas afficher un code technique à leur place.
     */
    private const BUILTIN = [
        'helloasso' => [
            'label'      => 'HelloAsso',
            'sentence'   => 'Réglez en ligne depuis la page HelloAsso du club. '
                . 'Le bureau confirmera la réception.',
            'link'       => [
                'label' => 'HelloAsso — adhésion',
                'help'  => 'La page de la campagne d’adhésion HelloAsso qui encaisse cette saison.',
            ],
            'link_label' => 'Payer mon adhésion en ligne',
        ],
        'cheque'    => ['label' => 'Chèque'],
        'virement'  => ['label' => 'Virement', 'offered' => false],
        'especes'   => ['label' => 'Espèces', 'offered' => false],
    ];

    /**
     * @return array<string, array{label: string, offered: bool, sentence: ?string, link: ?array{label: string, help: string}, link_label: ?string, third_party: ?array<string, mixed>}>
     */
    private static function definitions(): array
    {
        $definitions = [];

        foreach ((array) apply_filters(self::FILTER, self::BUILTIN) as $method => $definition) {
            $method = sanitize_key((string) $method);

            if ($method === '' || !is_array($definition)) {
                continue;
            }

            $link = $definition['link'] ?? null;

            $definitions[$method] = [
                'label'       => (string) ($definition['label'] ?? $method),
                'offered'     => (bool) ($definition['offered'] ?? true),
                'sentence'    => isset($definition['sentence']) ? (string) $definition['sentence'] : null,
                'link'        => is_array($link)
                    ? ['label' => (string) ($link['label'] ?? ''), 'help' => (string) ($link['help'] ?? '')]
                    : null,
                'link_label'  => isset($definition['link_label']) ? (string) $definition['link_label'] : null,
                'third_party' => is_array($definition['third_party'] ?? null) ? $definition['third_party'] : null,
            ];
        }

        return $definitions;
    }

    /**
     * Ce que l'adhérent choisit au dépôt de son dossier.
     *
     * @return array<string, string>
     */
    public static function offered(): array
    {
        return array_map(
            static fn (array $d): string => $d['label'],
            array_filter(self::definitions(), static fn (array $d): bool => $d['offered'])
        );
    }

    /**
     * Tous les modes, retirés compris, pour relire l'historique.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_map(static fn (array $d): string => $d['label'], self::definitions());
    }

    public static function isOffered(string $method): bool
    {
        return isset(self::offered()[$method]);
    }

    public static function label(string $method): string
    {
        return self::all()[$method] ?? $method;
    }

    /**
     * Le tiers payeur de ce mode, s'il en déclare un.
     */
    public static function thirdParty(string $method): ?ThirdPartyShare
    {
        $definition = self::definitions()[$method]['third_party'] ?? null;

        return $definition === null ? null : ThirdPartyShare::fromDefinition($definition);
    }

    /**
     * Ce que l'onglet « Règlement » d'une campagne propose de remplir.
     *
     * Le chèque n'y figure pas : il n'encaisse pas en ligne, et a sa propre
     * consigne (à l'ordre de qui, quelle adresse) — voir [chequeFields] et le
     * formulaire dédié de l'écran de campagne. L'aide dit ce qu'on attend, pour
     * éviter qu'on colle l'adresse de l'adhésion dans la case d'un autre mode.
     *
     * @return array<string, array{label: string, help: string}>
     */
    public static function linkFields(): array
    {
        $fields = [];

        foreach (self::definitions() as $method => $definition) {
            if ($definition['offered'] && $definition['link'] !== null) {
                $fields[$method] = $definition['link'];
            }
        }

        return $fields;
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

        foreach (array_keys(self::offered()) as $method) {
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

        foreach (array_keys(self::offered()) as $method) {
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
     *
     * @param string $chequePayee  À l'ordre de qui établir le chèque, pour le
     *     mode chèque. Ignoré pour les autres modes.
     * @param string $chequeAddress Adresse à laquelle l'envoyer, pour le mode
     *     chèque. Ignorée pour les autres modes.
     */
    public static function instructions(
        string $method,
        string $link = '',
        string $chequePayee = '',
        string $chequeAddress = ''
    ): string {
        $text = self::sentence($method, $chequePayee, $chequeAddress);

        return $link === '' ? $text : $text . ' ' . $link;
    }

    /**
     * La même consigne, l'adresse en lien cliquable.
     *
     * Rendue déjà échappée : les appelants l'insèrent telle quelle, sans
     * repasser par `esc_html` qui afficherait la balise au lieu du lien.
     */
    public static function instructionsHtml(
        string $method,
        string $link = '',
        string $chequePayee = '',
        string $chequeAddress = ''
    ): string {
        $html = esc_html(self::sentence($method, $chequePayee, $chequeAddress));

        if ($link === '') {
            return $html;
        }

        return $html . sprintf(
            ' <a class="sub-payment-link" href="%s" rel="noopener" target="_blank">%s</a>',
            esc_url($link),
            esc_html(self::linkLabel($method))
        );
    }

    /** La consigne seule, sans lien de paiement en ligne. */
    private static function sentence(string $method, string $chequePayee = '', string $chequeAddress = ''): string
    {
        if ($method === 'cheque') {
            return self::chequeSentence($chequePayee, $chequeAddress);
        }

        return self::definitions()[$method]['sentence']
            ?? 'Le bureau confirmera la réception de votre règlement.';
    }

    /**
     * La consigne du chèque, à l'ordre de qui et à quelle adresse — quand la
     * campagne les a renseignés, sinon la formule générique d'avant, pour ne
     * rien casser sur une campagne pas encore configurée.
     */
    private static function chequeSentence(string $payee, string $address): string
    {
        $order = $payee === '' ? 'à l’ordre du club' : 'à l’ordre de ' . $payee;
        $send  = $address === '' ? 'remettez-le à la trésorerie' : 'envoyez-le à ' . $address;

        return 'Établissez votre chèque ' . $order . ' et ' . $send
            . '. Le bureau confirmera la réception.';
    }

    /** Ce que porte le lien : ce qu'on va payer, pas « cliquez ici ». */
    private static function linkLabel(string $method): string
    {
        return self::definitions()[$method]['link_label'] ?? 'Régler en ligne';
    }
}
