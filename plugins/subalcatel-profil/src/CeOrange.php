<?php

declare(strict_types=1);

namespace Subalcatel\Profil;

/**
 * Le règlement par le comité d'entreprise Orange.
 *
 * Une partie des adhérents de Sub Alcatel voit son adhésion prise en charge
 * par le CE Orange. Le CE règle tout — formule, licence, prêts, piscine,
 * remises comprises — sauf la carte de niveau et l'assurance individuelle,
 * que l'adhérent paie lui-même dans la boutique HelloAsso « CE Orange » de la
 * saison. Le club, lui, encaisse le même total.
 *
 * Le cœur sait faire ce partage pour n'importe quel tiers payeur
 * ({@see \Subalcatel\Club\Membership\ThirdPartyShare}) ; ce profil lui dit qui
 * paie, quoi, et où l'adhérent règle sa part.
 *
 * Les options se reconnaissent à leur nom technique, qui se reporte d'une
 * campagne à l'autre. Une option renommée dans l'écran de campagne sortirait de
 * la part de l'adhérent : la répartition serait fausse, le total non.
 */
final class CeOrange
{
    /** Clé du mode, enregistrée sur chaque dossier : ne pas la changer. */
    public const METHOD = 'ce_orange';

    /** Options que le CE ne prend pas en charge. */
    public const MEMBER_OPTIONS = ['carte_niveau', 'assurance_individuelle'];

    /**
     * @param array<string, array<string, mixed>> $methods
     * @return array<string, array<string, mixed>>
     */
    public static function addTo(array $methods): array
    {
        $methods[self::METHOD] = self::definition();

        return $methods;
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'label'       => 'CE Orange',
            'sentence'    => 'Votre règlement passe par le comité d’entreprise Orange. '
                . 'Le bureau confirmera la réception auprès du CE.',
            // HelloAsso ouvre une boutique CE Orange par saison : son adresse
            // vit sur la campagne, comme celle de l'adhésion.
            'link'        => [
                'label' => 'HelloAsso — boutique CE Orange',
                'help'  => 'La boutique de la saison, qui porte la carte de niveau et l’assurance.',
            ],
            'link_label'  => 'Boutique CE Orange — carte de niveau et assurance',
            'third_party' => [
                'payer'          => 'Le CE Orange',
                'by'             => 'par le CE Orange',
                'member_options' => self::MEMBER_OPTIONS,
                'member_label'   => 'carte de niveau et assurance',
                'member_where'   => 'dans la boutique CE Orange',
            ],
        ];
    }
}
