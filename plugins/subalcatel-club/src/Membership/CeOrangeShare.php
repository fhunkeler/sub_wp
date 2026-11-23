<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Ce que règle le CE Orange, et ce qui reste à la charge de l'adhérent.
 *
 * Un dossier réglé par le CE affiche le total que le club encaisse au bout du
 * compte. Le bureau a reçu en novembre 2026 la question d'une nouvelle
 * adhérente : quel que soit le mode choisi, le montant ne bougeait pas, alors
 * qu'elle s'attendait à ne payer que la carte de niveau et l'assurance. Le
 * total était juste ; il manquait la répartition.
 *
 * Le CE prend en charge l'adhésion — formule, licence, prêts, piscine, remises
 * comprises. La carte de niveau et l'assurance individuelle restent à la
 * charge de l'adhérent, qui les règle dans la boutique HelloAsso CE Orange de
 * la saison.
 *
 * Les options se reconnaissent à leur nom technique, qui se reporte d'une
 * campagne à l'autre. Une option renommée dans l'écran de campagne sortirait
 * de la part de l'adhérent : la répartition serait fausse, le total non.
 */
final class CeOrangeShare
{
    public const METHOD = 'ce_orange';

    /** Options que le CE ne prend pas en charge. */
    public const MEMBER_OPTIONS = ['carte_niveau', 'assurance_individuelle'];

    /**
     * Répartition d'un devis calculé (formulaire en cours de saisie).
     *
     * @return array{ce: float, member: float}
     */
    public static function fromQuote(Quote $quote): array
    {
        $member = 0.0;

        foreach ($quote->lines as $line) {
            if ($line->type === 'option' && in_array($line->sourceName, self::MEMBER_OPTIONS, true)) {
                $member += $line->amount;
            }
        }

        return self::split($quote->total(), $member);
    }

    /**
     * Répartition d'un dossier déposé, d'après ses lignes figées.
     *
     * @param list<array<string, mixed>> $lines Lignes de `sub_application_lines`.
     * @return array{ce: float, member: float}
     */
    public static function fromStoredLines(float $total, array $lines): array
    {
        $member = 0.0;

        foreach ($lines as $line) {
            if (($line['line_type'] ?? '') === 'option'
                && in_array((string) ($line['source_name'] ?? ''), self::MEMBER_OPTIONS, true)) {
                $member += (float) $line['amount'];
            }
        }

        return self::split($total, $member);
    }

    /**
     * La phrase dite à l'adhérent, en texte brut (courriel et pages).
     *
     * @param array{ce: float, member: float} $share
     */
    public static function sentence(array $share): string
    {
        $ce = 'Le CE Orange prend en charge ' . self::euro($share['ce']) . '.';

        if ($share['member'] < 0.005) {
            return $ce . ' Rien ne reste à votre charge.';
        }

        return $ce . ' Reste à votre charge : ' . self::euro($share['member'])
            . ' (carte de niveau et assurance), à régler dans la boutique CE Orange.';
    }

    /**
     * @return array{ce: float, member: float}
     */
    private static function split(float $total, float $member): array
    {
        // La part de l'adhérent ne dépasse jamais le total, et ne devient pas
        // un crédit : une remise ne s'impute pas sur la carte ou l'assurance.
        $member = round(min(max($member, 0.0), $total), 2);

        return ['ce' => round($total - $member, 2), 'member' => $member];
    }

    private static function euro(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' €';
    }
}
