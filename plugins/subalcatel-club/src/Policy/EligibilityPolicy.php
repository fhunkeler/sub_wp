<?php

declare(strict_types=1);

namespace Subalcatel\Club\Policy;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Identity\AccountApproval;
use Subalcatel\Club\Identity\DiveLevels;

/**
 * Point de décision unique du plugin.
 *
 * Toute question « cette personne a-t-elle le droit ? » passe ici. Aucun écran,
 * aucun formulaire, aucune route REST ne réimplémente une règle : ils appellent
 * ce service, à l'affichage ET à la soumission.
 *
 * Les méthodes renvoient une Decision plutôt qu'un booléen, parce que le motif
 * d'un refus doit toujours pouvoir être montré au membre. Une inscription
 * refusée sans explication produit un appel téléphonique au président ; une
 * inscription refusée avec motif produit une action de la part du membre.
 */
final class EligibilityPolicy
{
    /**
     * Le membre a-t-il une adhésion active à cette date ?
     *
     * À FAIRE (phase 4) : interroger sub_membership_applications. Tant que le
     * module Adhésions n'existe pas, on s'appuie sur une méta posée à la main,
     * ce qui permet de développer et démontrer les événements sans attendre.
     */
    /**
     * Le compte a-t-il été validé par le bureau ?
     *
     * C'est la toute première porte. N'importe qui peut créer un compte — c'est
     * ce qui permet à un futur adhérent de démarrer son dossier un dimanche
     * soir — mais créer un compte n'est pas entrer au club.
     */
    public function hasApprovedAccount(int $userId): Decision
    {
        return match (AccountApproval::statusOf($userId)) {
            AccountApproval::STATUS_PENDING => Decision::deny(
                'Votre compte est en attente de validation par le bureau. '
                . 'Vous recevrez un courriel dès qu’il sera validé.',
                Decision::ACCOUNT_PENDING
            ),
            AccountApproval::STATUS_REFUSED => Decision::deny(
                'Votre compte n’a pas été validé par le bureau. '
                . 'Contactez-le si vous pensez qu’il s’agit d’une erreur.',
                Decision::ACCOUNT_REFUSED
            ),
            default => Decision::allow(),
        };
    }

    public function hasActiveMembership(int $userId, ?string $onDate = null): Decision
    {
        $onDate ??= current_time('Y-m-d');
        $until = (string) get_user_meta($userId, 'sub_membership_valid_until', true);

        if ($until === '') {
            return Decision::deny('Aucune adhésion enregistrée.', Decision::NO_MEMBERSHIP);
        }

        if ($until < $onDate) {
            return Decision::deny(
                sprintf('Votre adhésion a expiré le %s.', self::formatDate($until)),
                Decision::MEMBERSHIP_EXPIRED
            );
        }

        return Decision::allow();
    }

    /**
     * Les documents obligatoires sont-ils valides ?
     *
     * Cette méthode ne lit AUCUN fichier : elle consulte des statuts et des
     * dates. C'est ce cloisonnement qui permet à tout le site de fonctionner
     * sans jamais approcher une donnée de santé.
     */
    public function hasValidDocuments(int $userId, ?string $onDate = null): Decision
    {
        $status = (new DocumentService())->statusFor($userId, $onDate);

        if ($status['missing'] !== []) {
            // Le document est complément d'objet, jamais sujet : la phrase
            // reste correcte que le libellé soit masculin ou féminin.
            return Decision::deny(
                sprintf('Vous n’avez pas déposé %s.', self::joinLabels($status['missing'])),
                Decision::DOCUMENT_MISSING
            );
        }

        if ($status['expired'] !== []) {
            $first = $status['expired'][0];

            return Decision::deny(
                sprintf(
                    'Votre %s a expiré le %s.',
                    lcfirst($first['label']),
                    self::formatDate($first['date'])
                ),
                Decision::DOCUMENT_EXPIRED
            );
        }

        // Un document en attente de validation ne bloque pas : le membre a fait
        // sa part, c'est au bureau de traiter. Il apparaît en revanche dans la
        // file de validation et sur la liste du directeur de plongée.
        return Decision::allow();
    }

    /**
     * « votre certificat médical » ou « votre certificat médical et votre licence FFESSM ».
     *
     * @param list<string> $labels
     */
    private static function joinLabels(array $labels): string
    {
        $prefixed = array_map(
            static fn (string $label): string => 'votre ' . lcfirst($label),
            $labels
        );

        if (count($prefixed) === 1) {
            return $prefixed[0];
        }

        $last = array_pop($prefixed);

        return implode(', ', $prefixed) . ' et ' . $last;
    }

    /**
     * Le membre atteint-il le niveau minimum requis ?
     *
     * Un niveau de plongée est un **rang**, pas une étiquette : qui est P5
     * exerce tout ce qu'exerce un P2. La question n'est donc pas « le niveau du
     * membre figure-t-il dans la liste ? » mais « atteint-il le plus bas de la
     * liste ? ». La différence n'est pas théorique : elle refusait à un P5/E2
     * une plongée ouverte aux P1, P3 et P5 — son libellé n'était dans aucune
     * case, alors que ses prérogatives couvraient les trois.
     *
     * Deux axes, comparés séparément puis réunis par un OU. Une exigence
     * « P3 » parle du rang de plongeur ; une exigence « E2 » parle du rang
     * d'encadrement — une sortie réservée aux encadrants, où le rang de
     * plongeur ne décide pas. Quand les deux sont demandés, satisfaire l'un
     * suffit : le bureau qui coche « P3 » et « E1 » ouvre aux plongeurs
     * autonomes **ou** aux encadrants, pas à ceux qui sont les deux.
     *
     * @param list<string> $acceptedSlugs Niveaux admis, lus comme un plancher.
     *                                    Vide = aucune exigence.
     */
    public function meetsDiveLevel(int $userId, array $acceptedSlugs): Decision
    {
        if ($acceptedSlugs === []) {
            return Decision::allow();
        }

        [$requiredDiver, $requiredTeaching] = self::thresholds($acceptedSlugs);

        // Des niveaux cochés mais aucun rang exploitable : le bureau n'a pas
        // encore réglé les rangs. Bloquer tout le monde sur une donnée
        // manquante serait le pire des deux mondes.
        if ($requiredDiver === 0 && $requiredTeaching === 0) {
            return Decision::allow();
        }

        $level = DiveLevels::forUser($userId);

        if ($level === null) {
            return Decision::deny('Votre niveau de plongée n’est pas renseigné.', Decision::LEVEL_MISSING);
        }

        [$diver, $teaching] = DiveLevels::ranksForUser($userId);

        $meets = ($requiredDiver > 0 && $diver >= $requiredDiver)
            || ($requiredTeaching > 0 && $teaching >= $requiredTeaching);

        if (!$meets) {
            return Decision::deny(
                sprintf(
                    'Niveau minimum requis : %s. Le vôtre : %s.',
                    implode(' ou ', self::labelsFor(self::floorSlugs($acceptedSlugs))),
                    $level->name
                ),
                Decision::LEVEL_TOO_LOW
            );
        }

        return Decision::allow();
    }

    /**
     * L'exigence de niveau, en une phrase lisible par un plongeur.
     *
     * Même réduction au plancher que les motifs de refus : annoncer « P1, P3,
     * P5 » alors que seul P1 compte ferait renoncer un P2 qui avait sa place.
     *
     * @param list<string> $acceptedSlugs
     */
    public function requirementLabel(array $acceptedSlugs): string
    {
        if ($acceptedSlugs === []) {
            return 'ouverte à tous';
        }

        return 'à partir de ' . implode(' ou ', self::labelsFor(self::floorSlugs($acceptedSlugs)));
    }

    /**
     * Plancher exigé sur chaque axe : [rang plongeur, rang encadrement].
     *
     * Zéro sur un axe signifie « cet axe n'est pas demandé ».
     *
     * @param list<string> $acceptedSlugs
     * @return array{0: int, 1: int}
     */
    private static function thresholds(array $acceptedSlugs): array
    {
        $diver    = [];
        $teaching = [];

        foreach ($acceptedSlugs as $slug) {
            $d = DiveLevels::rankOfSlug($slug, DiveLevels::RANK_DIVER);
            $t = DiveLevels::rankOfSlug($slug, DiveLevels::RANK_TEACHING);

            // Un niveau mixte — « P4/E2 » — dit deux choses. Coché comme
            // exigence, c'est son axe le plus spécifique qui parle : demander
            // « P4/E2 », c'est demander un encadrant E2, pas seulement un P4.
            if ($t > 0) {
                $teaching[] = $t;
            } elseif ($d > 0) {
                $diver[] = $d;
            }
        }

        return [
            $diver === [] ? 0 : min($diver),
            $teaching === [] ? 0 : min($teaching),
        ];
    }

    /**
     * Les niveaux cochés qui portent réellement le plancher, pour le message.
     *
     * Annoncer « requis : P1, P3, P5 » à qui est P2 est trompeur : seul P1
     * compte. On ne cite donc que le plus bas de chaque axe.
     *
     * @param list<string> $acceptedSlugs
     * @return list<string>
     */
    private static function floorSlugs(array $acceptedSlugs): array
    {
        [$diver, $teaching] = self::thresholds($acceptedSlugs);
        $floor = [];

        foreach ([DiveLevels::RANK_TEACHING => $teaching, DiveLevels::RANK_DIVER => $diver] as $axis => $required) {
            if ($required === 0) {
                continue;
            }

            foreach ($acceptedSlugs as $slug) {
                if (DiveLevels::rankOfSlug($slug, $axis) === $required) {
                    $floor[] = $slug;
                    break;
                }
            }
        }

        return $floor === [] ? $acceptedSlugs : $floor;
    }

    /**
     * Libellés lisibles des niveaux, pour les messages destinés aux membres.
     *
     * Un plongeur lit « P5, E3 », pas « p5, e3 ».
     *
     * @param list<string> $slugs
     * @return list<string>
     */
    private static function labelsFor(array $slugs): array
    {
        $labels = [];

        foreach ($slugs as $slug) {
            $term     = get_term_by('slug', $slug, DiveLevels::TAXONOMY);
            $labels[] = $term ? $term->name : $slug;
        }

        return $labels;
    }

    public function isAutonomousDiver(int $userId): bool
    {
        return DiveLevels::hasFlag($userId, DiveLevels::FLAG_AUTONOMOUS);
    }

    public function isInstructor(int $userId): bool
    {
        return DiveLevels::hasFlag($userId, DiveLevels::FLAG_INSTRUCTOR);
    }

    public function isDiveLeader(int $userId): bool
    {
        return DiveLevels::hasFlag($userId, DiveLevels::FLAG_DIVE_LEADER);
    }

    /**
     * Droit d'emprunter un type de matériel, ouvert par une option d'adhésion.
     *
     * Le module Emprunts arrive en phase 8, mais les options « prêt bloc /
     * détendeur / gilet / ordinateur » sont souscrites et payées dès
     * l'adhésion. Le droit est donc enregistré dès la phase 4 : sans cela,
     * chaque campagne écoulée produirait des emprunts sans droit rattachable.
     */
    public function hasLendingRight(int $userId, string $equipmentType): Decision
    {
        $membership = $this->hasActiveMembership($userId);
        if (!$membership->allowed) {
            return $membership;
        }

        $granted = (array) get_user_meta($userId, 'sub_lending_rights', true);

        if (!in_array($equipmentType, $granted, true)) {
            return Decision::deny(
                sprintf('L’option « prêt %s » n’a pas été souscrite avec votre adhésion.', $equipmentType),
                Decision::OPTION_MISSING
            );
        }

        return Decision::allow();
    }

    /**
     * Toutes les conditions d'inscription à une sortie, évaluées ensemble.
     *
     * L'ordre compte : on renvoie le motif le plus actionnable en premier.
     *
     * @param list<string> $acceptedLevels
     */
    public function canRegisterForDive(int $userId, array $acceptedLevels = []): Decision
    {
        foreach ([
            $this->hasApprovedAccount($userId),
            $this->hasActiveMembership($userId),
            $this->hasValidDocuments($userId),
            $this->meetsDiveLevel($userId, $acceptedLevels),
        ] as $decision) {
            if (!$decision->allowed) {
                return $decision;
            }
        }

        return Decision::allow();
    }

    private static function formatDate(string $isoDate): string
    {
        $timestamp = strtotime($isoDate);

        return $timestamp === false ? $isoDate : wp_date('d/m/Y', $timestamp);
    }
}
