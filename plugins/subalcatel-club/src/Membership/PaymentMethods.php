<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Modes de règlement d'une adhésion.
 *
 * Le club en écarte deux volontairement : l'espèce, qui ne laisse pas de trace
 * exploitable pour la trésorerie d'une association, et le virement, dont le
 * rapprochement coûtait plus de temps aux bénévoles qu'il n'en faisait gagner.
 * Ils restent nommés plus bas, parce que des règlements anciens les portent et
 * qu'un export ne doit pas afficher un code technique à leur place.
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
     * Ce que l'adhérent doit faire, une fois son dossier déposé.
     */
    public static function instructions(string $method): string
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
}
