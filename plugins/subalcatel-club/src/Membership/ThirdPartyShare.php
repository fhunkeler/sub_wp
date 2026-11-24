<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Ce que règle un tiers payeur, et ce qui reste à la charge de l'adhérent.
 *
 * Un comité d'entreprise, une mairie, un employeur peuvent prendre en charge
 * l'adhésion d'un membre. Le club encaisse alors le même total, mais l'adhérent
 * ne paie lui-même qu'une partie — souvent la carte de niveau et l'assurance.
 * Un dossier qui n'affiche que le total laisse l'adhérent croire qu'il doit
 * tout : c'est la question qu'une nouvelle adhérente a posée au bureau de
 * Sub Alcatel en novembre 2026, pour le CE Orange. Le total était juste ; il
 * manquait la répartition.
 *
 * Le tiers payeur se déclare sur un mode de règlement
 * ({@see PaymentMethods::FILTER}, clé `third_party`) :
 *
 *   - `payer`          sujet de la phrase : « Le CE Orange » ;
 *   - `by`             complément : « par le CE Orange » ;
 *   - `member_options` noms techniques des options qui restent à l'adhérent ;
 *   - `member_label`   ce qu'elles sont, en clair : « carte de niveau et assurance » ;
 *   - `member_where`   où les régler : « dans la boutique CE Orange ».
 *
 * Les options se reconnaissent à leur nom technique, qui se reporte d'une
 * campagne à l'autre. Une option renommée dans l'écran de campagne sortirait
 * de la part de l'adhérent : la répartition serait fausse, le total non.
 */
final class ThirdPartyShare
{
    /**
     * @param list<string> $memberOptions
     */
    public function __construct(
        public readonly string $payer,
        public readonly string $by,
        public readonly array $memberOptions,
        public readonly string $memberLabel,
        public readonly string $memberWhere,
    ) {
    }

    /**
     * Le tiers payeur du mode de règlement choisi, s'il y en a un.
     */
    public static function forMethod(string $method): ?self
    {
        return PaymentMethods::thirdParty($method);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromDefinition(array $definition): self
    {
        $payer = trim((string) ($definition['payer'] ?? '')) ?: 'Le tiers payeur';

        return new self(
            $payer,
            trim((string) ($definition['by'] ?? '')) ?: 'par ' . lcfirst($payer),
            array_values(array_map('strval', (array) ($definition['member_options'] ?? []))),
            trim((string) ($definition['member_label'] ?? '')),
            trim((string) ($definition['member_where'] ?? '')),
        );
    }

    /**
     * Répartition d'un devis calculé (formulaire en cours de saisie).
     *
     * @return array{payer: float, member: float}
     */
    public function fromQuote(Quote $quote): array
    {
        $member = 0.0;

        foreach ($quote->lines as $line) {
            if ($line->type === 'option' && in_array($line->sourceName, $this->memberOptions, true)) {
                $member += $line->amount;
            }
        }

        return self::split($quote->total(), $member);
    }

    /**
     * Répartition d'un dossier déposé, d'après ses lignes figées.
     *
     * @param list<array<string, mixed>> $lines Lignes de `sub_application_lines`.
     * @return array{payer: float, member: float}
     */
    public function fromStoredLines(float $total, array $lines): array
    {
        $member = 0.0;

        foreach ($lines as $line) {
            if (($line['line_type'] ?? '') === 'option'
                && in_array((string) ($line['source_name'] ?? ''), $this->memberOptions, true)) {
                $member += (float) $line['amount'];
            }
        }

        return self::split($total, $member);
    }

    /**
     * La phrase dite à l'adhérent, en texte brut (courriel et pages).
     *
     * @param array{payer: float, member: float} $share
     */
    public function sentence(array $share): string
    {
        $payer = $this->payer . ' prend en charge ' . self::euro($share['payer']) . '.';

        if ($share['member'] < 0.005) {
            return $payer . ' Rien ne reste à votre charge.';
        }

        return $payer . ' Reste à votre charge : ' . self::euro($share['member'])
            . ($this->memberLabel === '' ? '' : ' (' . $this->memberLabel . ')')
            . ($this->memberWhere === '' ? '' : ', à régler ' . $this->memberWhere)
            . '.';
    }

    /** « Pris en charge par le CE Orange » — l'intitulé de la ligne du tiers. */
    public function payerLine(): string
    {
        return 'Pris en charge ' . $this->by;
    }

    /** Ce qui reste à l'adhérent et où le régler, une fois le dossier déposé. */
    public function memberHelp(): string
    {
        $what = $this->memberLabel === '' ? 'Le reste' : ucfirst($this->memberLabel);

        return $what . ($this->memberWhere === '' ? '' : ', à régler ' . $this->memberWhere)
            . ' une fois le dossier déposé.';
    }

    /**
     * @return array{payer: float, member: float}
     */
    private static function split(float $total, float $member): array
    {
        // La part de l'adhérent ne dépasse jamais le total, et ne devient pas
        // un crédit : une remise ne s'impute pas sur la carte ou l'assurance.
        $member = round(min(max($member, 0.0), $total), 2);

        return ['payer' => round($total - $member, 2), 'member' => $member];
    }

    private static function euro(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' €';
    }
}
