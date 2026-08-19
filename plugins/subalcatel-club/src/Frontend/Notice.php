<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

/**
 * Encarts de retour après une action.
 *
 * Deux défauts se répétaient dans les six écrans qui affichent un retour de
 * formulaire, et cette classe existe pour les fermer d'un seul endroit.
 *
 * 1. **L'état ne tenait qu'à une couleur.** Les variantes de `.sub-notice` ne
 *    différaient que par un liseré de 4 px sur un fond identique : « mot de
 *    passe incorrect » avait l'apparence d'un encart d'information. Le mot
 *    d'état ouvre donc désormais le message, et le fond change avec lui.
 *
 * 2. **Rien n'était annoncé.** Le retour arrive après une redirection, dans un
 *    `<div>` muet en haut de page : une personne au lecteur d'écran ne savait
 *    pas que son dépôt de certificat avait échoué. `role="alert"` porte les
 *    erreurs, `role="status"` les confirmations.
 *
 * Une erreur prend en plus le focus (`tabindex="-1"` + `autofocus`) : un
 * `role="alert"` déjà présent au chargement de la page n'est pas annoncé de
 * façon fiable par tous les lecteurs d'écran, alors qu'un élément qui reçoit
 * le focus l'est toujours. Réservé aux erreurs : déplacer le focus sur chaque
 * confirmation serait intrusif.
 *
 * Les encarts d'état de page — « Espace réservé aux membres », « Aucune sortie
 * programmée » — ne passent pas par ici : ils décrivent la page, ils
 * n'annoncent pas le résultat d'une action, et `role="alert"` y serait faux.
 */
final class Notice
{
    /**
     * Mot d'état ouvrant le message.
     *
     * Il porte l'information pour qui ne distingue pas les couleurs (WCAG
     * 1.4.1), et sert de repère à la lecture rapide.
     *
     * @var array<string, string>
     */
    private const ETATS = [
        'error'   => 'Erreur',
        'success' => 'C’est fait',
        'warning' => 'Attention',
        'info'    => 'Information',
    ];

    /**
     * Retours transmis en paramètre d'URL après une redirection `admin-post`.
     *
     * @param string $extraClass Classe supplémentaire, ex. `sub-noprint`.
     */
    public static function fromQuery(string $extraClass = ''): string
    {
        $html = '';

        foreach (['sub_done' => 'success', 'sub_error' => 'error'] as $key => $type) {
            if (!isset($_GET[$key])) {
                continue;
            }

            $html .= self::feedback(
                $type,
                sanitize_text_field(wp_unslash((string) $_GET[$key])),
                $extraClass
            );
        }

        return $html;
    }

    /**
     * @param string $type    success | error | warning | info
     * @param string $message Texte brut ; il est échappé ici.
     */
    public static function feedback(string $type, string $message, string $extraClass = ''): string
    {
        $type  = isset(self::ETATS[$type]) ? $type : 'info';
        $error = 'error' === $type;

        return sprintf(
            '<div class="sub-notice sub-notice--%1$s%2$s" role="%3$s"%4$s>'
            . '<p><span class="sub-notice__etat">%5$s — </span>%6$s</p></div>',
            esc_attr($type),
            '' === $extraClass ? '' : ' ' . esc_attr($extraClass),
            $error ? 'alert' : 'status',
            $error ? ' tabindex="-1" autofocus' : '',
            esc_html(self::ETATS[$type]),
            esc_html($message)
        );
    }
}
