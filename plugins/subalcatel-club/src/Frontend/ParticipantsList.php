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
        // Réservé aux adhérents à jour, sur décision du club. Un compte en
        // attente de validation ou une adhésion expirée voit le nombre de
        // places, jamais la liste nominative : afficher qui plonge est une
        // donnée personnelle, partagée entre membres, pas au-delà.
        if (!\Subalcatel\Club\Content\ClubDocuments::isActiveMember($userId)) {
            return;
        }

        $people = $service->socialParticipants($eventId, $userId);

        if ($people === []) {
            return;
        }

        $confirmed = array_values(array_filter($people, static fn (array $p): bool => $p['status'] === 'confirmed'));
        $waiting   = array_values(array_filter($people, static fn (array $p): bool => $p['status'] === 'waiting'));
        $festive   = count(array_filter($people, static fn (array $p): bool => $p['conviviality']));
        ?>
        <details class="sub-event__people">
            <summary>
                <?php echo esc_html(sprintf(
                    'Participants (%d inscrit%s%s)',
                    count($confirmed),
                    count($confirmed) > 1 ? 's' : '',
                    $waiting === [] ? '' : sprintf(', %d en attente', count($waiting))
                )); ?>
                <?php if ($festive > 0) : ?>
                    <?php // Repérable d'un coup d'œil : quelqu'un propose-t-il un pot ? ?>
                    <span class="sub-people__festive-count" title="<?php echo esc_attr(sprintf(
                        '%d participant(s) propose(nt) un pot', $festive
                    )); ?>">🍻 <?php echo (int) $festive; ?></span>
                <?php endif; ?>
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
                            <span class="sub-people__festive" title="Propose un moment convivial (pot, repas…)">🍻 propose un pot</span>
                        <?php endif; ?>
                        <?php if ($p['note'] !== '') : ?>
                            <span class="sub-people__note">« <?php echo esc_html($p['note']); ?> »</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }
}
