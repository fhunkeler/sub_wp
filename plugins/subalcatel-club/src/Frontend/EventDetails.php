<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Events\EventService;
use Subalcatel\Club\Policy\EligibilityPolicy;

/**
 * Le détail d'une sortie, à déplier sur place.
 *
 * La carte d'une sortie ne dit que le titre, la date et le lieu. Pour décider
 * d'y aller, on cherche le reste : ce qu'en dit l'organisateur, l'heure de
 * retour, qui dirige la plongée, le niveau demandé, jusqu'à quand s'inscrire.
 * Tout cela était saisi et n'apparaissait nulle part côté membre.
 *
 * Replié dans un `<details>`, comme la liste des participants : l'espace
 * membre reste une liste qu'on parcourt, et chaque sortie s'ouvre sans
 * quitter la page. Partagé avec l'agenda, pour que le détail soit le même
 * partout.
 *
 * Rien du dossier des inscrits ici : ce sont les informations de la sortie,
 * les mêmes pour tous ceux qui la voient.
 */
final class EventDetails
{
    /**
     * @param array<string, mixed> $event
     */
    public static function render(EventService $service, array $event): void
    {
        $rows = self::rows($service, $event);
        $text = trim((string) ($event['description'] ?? ''));
        ?>
        <details class="sub-event__details">
            <summary>Détails de la sortie</summary>

            <?php if ($text !== '') : ?>
                <div class="sub-event__description">
                    <?php
                    // La description vient de l'administration (HTML filtré par
                    // `wp_kses_post` à la saisie) ou du formulaire de l'espace
                    // (texte brut) : le même filtre convient aux deux.
                    echo wpautop(wp_kses_post($text));
                    ?>
                </div>
            <?php endif; ?>

            <dl class="sub-event__facts">
                <?php foreach ($rows as $label => $value) : ?>
                    <dt><?php echo esc_html($label); ?></dt>
                    <dd><?php echo esc_html($value); ?></dd>
                <?php endforeach; ?>
            </dl>
        </details>
        <?php
    }

    /**
     * Les informations de la sortie, dans l'ordre où l'on se les demande.
     *
     * Une ligne sans valeur est omise plutôt qu'affichée vide : « Fin : — »
     * n'apprend rien à personne.
     *
     * @param array<string, mixed> $event
     * @return array<string, string>
     */
    private static function rows(EventService $service, array $event): array
    {
        $eventId = (int) $event['id'];
        $type    = $service->typeOf($eventId);
        $rows    = [];

        if ($type !== null && !empty($type['name'])) {
            $rows['Type'] = (string) $type['name'];
        }

        $rows['Départ'] = MemberDashboard::frDateTime((string) $event['starts_at']);

        if (!empty($event['ends_at'])) {
            $rows['Retour'] = MemberDashboard::frDateTime((string) $event['ends_at']);
        }

        if (!empty($event['location'])) {
            $rows['Lieu'] = (string) $event['location'];
        }

        $leader    = self::name((int) ($event['dive_leader_id'] ?? 0));
        $organizer = self::name((int) ($event['organizer_id'] ?? 0));

        if ($leader !== '') {
            $rows['Directeur de plongée'] = $leader;
        }

        if ($organizer !== '' && $organizer !== $leader) {
            $rows['Organisateur'] = $organizer;
        }

        $levels = (array) (json_decode((string) ($event['accepted_levels'] ?? ''), true) ?: []);
        $rows['Niveau'] = self::capitalize((new EligibilityPolicy())->requirementLabel(
            array_values(array_filter(array_map('strval', $levels)))
        ));

        $capacity = (int) $event['capacity'];
        $rows['Places'] = $capacity > 0
            ? sprintf(
                '%d inscrit(s) sur %d%s',
                $service->confirmedCount($eventId),
                $capacity,
                (int) ($event['allow_waiting_list'] ?? 0) === 1 ? ', puis liste d’attente' : ''
            )
            : 'Non limitées';

        $closes = (string) ($event['registration_closes_at'] ?: $event['starts_at']);
        $rows['Inscriptions jusqu’au'] = MemberDashboard::frDateTime($closes);

        $required = [];
        if ((int) ($event['requires_membership'] ?? 0) === 1) {
            $required[] = 'adhésion à jour';
        }
        if ((int) ($event['requires_medical'] ?? 0) === 1) {
            $required[] = 'certificat médical valide';
        }
        if ($required !== []) {
            $rows['Conditions'] = self::capitalize(implode(', ', $required));
        }

        return $rows;
    }

    /**
     * Majuscule initiale, accents compris : `ucfirst()` laisse « à partir de »
     * en minuscule, faute de lire l'UTF-8.
     */
    private static function capitalize(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private static function name(int $userId): string
    {
        if ($userId === 0) {
            return '';
        }

        $user = get_userdata($userId);

        return $user instanceof \WP_User ? trim((string) $user->display_name) : '';
    }
}
