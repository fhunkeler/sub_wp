<?php

declare(strict_types=1);

namespace Subalcatel\Club\Communication;

use RuntimeException;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Support\Audit;

/**
 * Messages écrits par les adhérents aux listes de diffusion.
 *
 * Un autonome qui cherche un binôme pour dimanche, une question aux moniteurs,
 * une information à tout le club : jusqu'ici, il fallait passer par le bureau
 * ou avoir gardé les adresses d'un ancien courriel. Les listes existent déjà,
 * calculées par [MailingLists] ; ce module permet à un adhérent à jour de leur
 * écrire, sans voir une seule adresse.
 *
 * Trois règles, toutes tenues ici plutôt que dans le formulaire :
 *
 * - seul un adhérent à jour écrit — un ancien membre n'a plus à solliciter le
 *   club, ni un compte en attente de validation ;
 * - le message part du site, l'auteur en adresse de réponse : on répond à lui,
 *   jamais à toute la liste, et personne n'a publié son courriel ;
 * - un plafond par jour et par auteur. Le quota d'envoi est commun à tout le
 *   site — la réinitialisation d'un mot de passe en dépend aussi —, et trois
 *   messages à tout le club en épuisent l'essentiel.
 *
 * Le bureau n'a ni plafond ni restriction de liste : il écrit déjà à tous par
 * d'autres voies, et c'est lui qui répond du quota.
 */
final class ListMessages
{
    /** Messages qu'un adhérent peut envoyer par jour, toutes listes confondues. */
    public const DAILY_LIMIT = 3;

    private const META_COUNTER = 'sub_list_messages_today';

    /** Capacité qui lève le plafond et ouvre toutes les listes. */
    private const OFFICE_CAP = 'sub_manage_content';

    /**
     * Listes ouvertes à tout adhérent, avec ce qu'en lit l'auteur.
     *
     * Le libellé du bureau (« Encadrants ») décrit une règle ; celui-ci dit à
     * qui l'on écrit, avec les mots d'un plongeur.
     */
    private const MEMBER_LISTS = [
        MailingLists::ACTIVE     => [
            'label' => 'Tout le club',
            'help'  => 'Tous les adhérents à jour. Pour une information qui concerne chacun.',
        ],
        MailingLists::AUTONOMOUS => [
            'label' => 'Plongeurs autonomes',
            'help'  => 'Pour monter une plongée, chercher un binôme ou une place en voiture.',
        ],
        MailingLists::INSTRUCTOR => [
            'label' => 'Encadrants et moniteurs',
            'help'  => 'Initiateurs et moniteurs : une question de formation, une demande d’encadrement.',
        ],
        MailingLists::LEADER     => [
            'label' => 'Directeurs de plongée',
            'help'  => 'Pour proposer ou organiser une sortie.',
        ],
        MailingLists::OFFICE     => [
            'label' => 'Bureau',
            'help'  => 'Président, secrétaire, trésorier et membres du bureau.',
        ],
    ];

    public static function canSend(int $userId): bool
    {
        if ($userId === 0) {
            return false;
        }

        return user_can($userId, self::OFFICE_CAP) || self::isActive($userId);
    }

    /**
     * Listes auxquelles ce membre peut écrire, dans l'ordre d'affichage.
     *
     * @return list<array{slug: string, label: string, help: string}>
     */
    public static function listsFor(int $userId): array
    {
        if (!self::canSend($userId)) {
            return [];
        }

        $office = user_can($userId, self::OFFICE_CAP);
        $lists  = [];

        // Les listes des adhérents d'abord, dans l'ordre où on y pense : tout
        // le club, puis de la plus large à la plus étroite. Le bureau voit
        // ensuite les autres — anciens adhérents, niveaux, groupes.
        foreach (self::MEMBER_LISTS as $slug => $member) {
            if (MailingLists::find($slug) !== null) {
                $lists[] = ['slug' => $slug] + $member;
            }
        }

        foreach ($office ? MailingLists::all() : [] as $list) {
            if (!isset(self::MEMBER_LISTS[$list['slug']])) {
                $lists[] = ['slug' => $list['slug'], 'label' => $list['label'], 'help' => $list['description']];
            }
        }

        /** @var list<array{slug: string, label: string, help: string}> */
        return apply_filters('subalcatel_list_messages_lists', $lists, $userId);
    }

    /**
     * Destinataires réels d'un message, l'auteur excepté.
     *
     * Les listes par niveau comptent aussi ceux qui n'ont pas renouvelé : un
     * moniteur garde son niveau après son départ. Écrire « aux encadrants »,
     * c'est écrire aux encadrants du club, d'où le filtre sur l'adhésion. Le
     * bureau y échappe : il est défini par une fonction, pas par l'adhésion.
     *
     * @return list<int>
     */
    public static function recipients(string $slug, int $authorId): array
    {
        $ids = [];

        foreach (MailingLists::recipients($slug) as $recipient) {
            if ($recipient['id'] === $authorId) {
                continue;
            }

            if ($slug !== MailingLists::OFFICE && !self::isActive($recipient['id'])) {
                continue;
            }

            $ids[] = $recipient['id'];
        }

        return $ids;
    }

    /**
     * Ce membre peut-il voir, avant d'envoyer, qui recevra son message ?
     *
     * Quiconque peut écrire : savoir qui lira est la première chose dont on a
     * besoin pour écrire juste — « qui est autonome pour dimanche ? » ne se
     * pose pas pareil à trois personnes qu'à quarante. Les noms seulement :
     * les adresses restent cachées, c'est ce que le formulaire promet.
     */
    public static function canSeeRecipients(int $userId): bool
    {
        return self::canSend($userId);
    }

    /**
     * Noms affichés des destinataires, par ordre alphabétique.
     *
     * @param list<int> $ids
     * @return list<string>
     */
    public static function recipientNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = array_map(
            static fn (\WP_User $user): string => $user->display_name,
            get_users(['include' => $ids, 'fields' => 'all'])
        );

        usort($names, static fn (string $a, string $b): int => strcmp(
            remove_accents(mb_strtolower($a)),
            remove_accents(mb_strtolower($b))
        ));

        return $names;
    }

    /**
     * Messages qu'il reste à ce membre aujourd'hui ; `null` sans plafond.
     */
    public static function remainingToday(int $userId): ?int
    {
        if (user_can($userId, self::OFFICE_CAP)) {
            return null;
        }

        return max(0, self::DAILY_LIMIT - self::sentToday($userId));
    }

    /**
     * @return array{recipients: int, sent: int, queued: int}
     */
    public static function send(string $slug, string $subject, string $message, int $authorId): array
    {
        if (!self::canSend($authorId)) {
            throw new RuntimeException('Seuls les adhérents à jour de leur cotisation peuvent écrire aux listes du club.');
        }

        $list = null;

        foreach (self::listsFor($authorId) as $candidate) {
            if ($candidate['slug'] === $slug) {
                $list = $candidate;
            }
        }

        // Même réponse pour une liste inconnue et une liste fermée : la seconde
        // n'a pas à être découverte en essayant des noms.
        if ($list === null) {
            throw new RuntimeException('Cette liste n’existe pas, ou vous ne pouvez pas y écrire.');
        }

        $subject = trim($subject);
        $message = trim($message);

        if ($subject === '' || $message === '') {
            throw new RuntimeException('L’objet et le message sont obligatoires.');
        }

        if (self::remainingToday($authorId) === 0) {
            throw new RuntimeException(sprintf(
                'Vous avez déjà envoyé %d messages aux listes aujourd’hui. Le plafond protège '
                . 'l’envoi des courriels du site, partagé par tous : réessayez demain.',
                self::DAILY_LIMIT
            ));
        }

        $recipients = self::recipients($slug, $authorId);

        if ($recipients === []) {
            throw new RuntimeException('Cette liste ne compte aucun destinataire pour l’instant.');
        }

        $author = get_userdata($authorId);

        if (!$author instanceof \WP_User) {
            throw new RuntimeException('Expéditeur introuvable.');
        }

        // Par tranches plutôt que d'un bloc : un message au club peut arriver
        // le lendemain à une partie des destinataires, il ne doit pas attendre
        // en entier qu'une journée entière se libère. Passé trois jours, il a
        // perdu son objet — une plongée se monte pour le week-end.
        $outcome = Mailer::toUsersOrQueue(
            EmailTemplates::LIST_MESSAGE,
            $recipients,
            [
                'liste'      => $list['label'],
                'objet'      => $subject,
                'message'    => $message,
                'expediteur' => $author->display_name,
            ],
            ['entity_type' => 'mailing_list', 'sender_id' => $authorId],
            [sprintf('Reply-To: %s <%s>', $author->display_name, $author->user_email)],
            [
                'atomic'     => false,
                'expires_at' => gmdate('Y-m-d H:i:s', strtotime(current_time('mysql') . ' +3 days')),
                'label'      => sprintf('Message à la liste « %s »', $list['label']),
            ]
        );

        self::countOne($authorId);

        Audit::log('mailing_list.message_sent', 'mailing_list', null, [
            'list'       => $slug,
            'subject'    => $subject,
            'recipients' => count($recipients),
            'sent'       => $outcome['sent'],
            'queued'     => $outcome['queued'],
        ], $authorId);

        return ['recipients' => count($recipients)] + $outcome;
    }

    private static function isActive(int $userId): bool
    {
        return (string) get_user_meta($userId, 'sub_membership_valid_until', true) >= current_time('Y-m-d');
    }

    private static function sentToday(int $userId): int
    {
        $counter = (array) get_user_meta($userId, self::META_COUNTER, true);

        return ($counter['day'] ?? '') === current_time('Y-m-d') ? (int) ($counter['count'] ?? 0) : 0;
    }

    private static function countOne(int $userId): void
    {
        update_user_meta($userId, self::META_COUNTER, [
            'day'   => current_time('Y-m-d'),
            'count' => self::sentToday($userId) + 1,
        ]);
    }
}
