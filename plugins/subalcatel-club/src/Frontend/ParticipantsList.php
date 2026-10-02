<?php

declare(strict_types=1);

namespace Subalcatel\Club\Frontend;

use Subalcatel\Club\Events\EventService;

/**
 * Qui vient : la liste sociale des inscrits d'un événement.
 *
 * Partagée entre l'agenda et l'espace membre — tableau de bord et « Mes
 * inscriptions » : un adhérent inscrit à l'assemblée générale ou à une sortie
 * doit voir les autres inscrits là où il retrouve sa propre inscription, sans
 * repasser par l'agenda. Même règle d'accès partout, fixée ici.
 */
final class ParticipantsList
{
    /**
     * Liste repliée des participants d'un événement.
     *
     * Repliée dans un `<details>` : on la déroule pour choisir un binôme ou
     * voir qui vient, elle n'encombre pas la carte de la sortie. Ne montre que
     * nom, niveau, statut et le mot que chacun a choisi de partager — jamais les
     * données du dossier, qui restent au directeur de plongée.
     */
    public static function render(EventService $service, int $eventId, int $userId): void
    {
        // Réservé aux adhérents à jour, sur décision du club, qu'ils soient
        // inscrits à la sortie ou non : savoir qui vient aide à se décider. Un
        // compte en attente de validation ou une adhésion expirée voit le
        // nombre de places, jamais la liste nominative : afficher qui plonge
        // est une donnée personnelle, partagée entre membres, pas au-delà.
        if (!\Subalcatel\Club\Content\ClubDocuments::isActiveMember($userId)) {
            return;
        }

        $people    = $service->socialParticipants($eventId, $userId);
        $confirmed = array_values(array_filter($people, static fn (array $p): bool => $p['status'] === 'confirmed'));
        $waiting   = array_values(array_filter($people, static fn (array $p): bool => $p['status'] === 'waiting'));
        $festive   = array_column(
            array_filter($people, static fn (array $p): bool => $p['conviviality']),
            'name'
        );
        ?>
        <div class="sub-event__people">
            <?php if ($people === []) : ?>
                <?php // Ne rien afficher laissait croire la liste réservée aux inscrits. ?>
                <p class="sub-people__empty">Aucun inscrit pour l’instant.</p>
            <?php else : ?>
                <?php if ($festive !== []) : ?>
                    <?php
                    // Hors de la liste repliée, et en toutes lettres : c'est
                    // souvent la première chose qu'on cherche, et une infobulle
                    // ne s'ouvre pas sur un téléphone.
                    ?>
                    <p class="sub-people__festive-count">
                        🍻 <?php echo esc_html(sprintf('Pot proposé par %s', self::names($festive))); ?>
                    </p>
                <?php endif; ?>

                <details>
                    <summary>
                        <?php echo esc_html(sprintf(
                            'Participants (%d inscrit%s%s)',
                            count($confirmed),
                            count($confirmed) > 1 ? 's' : '',
                            $waiting === [] ? '' : sprintf(', %d en attente', count($waiting))
                        )); ?>
                    </summary>

                    <ul class="sub-people">
                        <?php foreach (array_merge($confirmed, $waiting) as $p) : ?>
                            <li class="sub-people__item <?php echo $p['is_self'] ? 'sub-people__item--self' : ''; ?>">
                                <span class="sub-people__name">
                                    <?php echo esc_html($p['name']); ?>
                                    <?php if ($p['is_self']) : ?><span class="sub-people__you">(vous)</span><?php endif; ?>
                                </span>
                                <?php if ($p['level'] !== '') : ?>
                                    <span class="sub-people__level"><?php echo esc_html($p['level']); ?></span>
                                <?php endif; ?>
                                <?php if ($p['status'] === 'waiting') : ?>
                                    <span class="sub-people__wait">liste d’attente</span>
                                <?php endif; ?>
                                <?php if ($p['conviviality']) : ?>
                                    <span class="sub-people__festive" title="Propose un moment convivial (pot, repas…)">🍻 pot</span>
                                <?php endif; ?>
                                <?php if ($p['note'] !== '') : ?>
                                    <span class="sub-people__note">« <?php echo esc_html($p['note']); ?> »</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * « Léa », « Léa et Tom », « Léa, Tom et Max ».
     *
     * @param list<string> $names
     */
    private static function names(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' et ' . $last;
    }
}
