<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

use Subalcatel\Club\Documents\DocumentService;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\MailQueue;
use Subalcatel\Club\Notifications\Mailer;

/**
 * Annonce aux adhérents de l'ouverture d'une campagne.
 *
 * Vivait dans l'écran des campagnes, d'où l'entretien quotidien devait aller
 * la chercher : une tâche de fond qui dépendait d'un écran d'administration.
 * C'est une règle d'adhésion, elle loge avec elles.
 */
final class CampaignNotices
{
    /** Campagnes dont l'annonce d'ouverture attend que le quota d'envoi le permette. */
    public const OPTION_PENDING = 'subalcatel_pending_campaign_notices';

    /**
     * Prévient les adhérents actifs qu'une campagne vient de s'ouvrir.
     *
     * Sans ce mail, rien ne signale qu'une nouvelle saison a démarré tant que
     * l'ancienne n'est pas terminée — un adhérent encore sur la campagne
     * précédente ne voit que son propre rappel d'échéance, avec une date qui
     * ne correspond plus aux règles de la nouvelle campagne.
     *
     * Idempotent par campagne : rouvrir une campagne déjà annoncée (après
     * l'avoir fermée par erreur, par exemple) ne relance pas l'envoi.
     *
     * @return 'sent'|'postponed'|'skipped' `postponed` : le plafond d'envoi du
     *         jour ne le permettait pas, l'entretien quotidien le reprendra.
     */
    public static function opened(int $campaignId): string
    {
        global $wpdb;

        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT title, valid_from, valid_until FROM {$wpdb->prefix}sub_campaigns WHERE id = %d",
            $campaignId
        ), ARRAY_A);

        if (!$campaign) {
            return 'skipped';
        }

        $alreadySent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log
             WHERE template_code = %s AND entity_type = 'campaign' AND entity_id = %d AND status = 'sent'",
            EmailTemplates::CAMPAIGN_OPENED,
            $campaignId
        ));

        if ($alreadySent > 0 || MailQueue::hasJobFor(EmailTemplates::CAMPAIGN_OPENED, 'campaign', $campaignId)) {
            self::forgetPendingNotice($campaignId);

            return 'skipped';
        }

        $userIds = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}sub_applications
             WHERE status = 'active' AND user_id IS NOT NULL"
        );

        if ($userIds === []) {
            return 'skipped';
        }

        // L'ouverture ne peut pas attendre le mail : les inscriptions sont
        // possibles dès maintenant. Ce que le plafond du jour ne laisse pas
        // passer part de la file d'attente, sur autant de jours qu'il faut —
        // l'annonce n'a pas à arriver à tous le même jour. Avant la file, une
        // annonce plus grosse que le plafond quotidien ne partait jamais.
        $outcome = Mailer::toUsersOrQueue(EmailTemplates::CAMPAIGN_OPENED, array_map('intval', $userIds), [
            'campagne' => (string) $campaign['title'],
            'debut'    => DocumentService::frDate((string) $campaign['valid_from']),
            'fin'      => DocumentService::frDate((string) $campaign['valid_until']),
        ], [
            'entity_type' => 'campaign',
            'entity_id'   => $campaignId,
            'sender_id'   => get_current_user_id(),
        ], [], [
            'atomic' => false,
            'label'  => sprintf('Ouverture de « %s »', (string) $campaign['title']),
        ]);

        self::forgetPendingNotice($campaignId);

        return $outcome['queued'] > 0 ? 'postponed' : 'sent';
    }

    /**
     * Reprend les annonces d'ouverture reportées faute de quota.
     *
     * Depuis la file d'attente des envois ({@see MailQueue}), plus rien n'est
     * inscrit ici : la liste ne sert qu'à reprendre, une fois, les annonces
     * reportées par une version antérieure — elles rejoignent alors la file.
     *
     * Seules les campagnes explicitement mises en attente sont reprises : une
     * campagne ouverte avant l'existence de cette annonce n'en a aucune trace
     * dans le journal, et ne doit pas déclencher un envoi au club entier le
     * jour de la mise à jour.
     */
    public static function retryPending(): int
    {
        global $wpdb;

        $sent = 0;

        foreach (self::pendingNotices() as $campaignId) {
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}sub_campaigns WHERE id = %d",
                $campaignId
            ));

            if ($status !== 'open') {
                self::forgetPendingNotice($campaignId);
                continue;
            }

            $sent += self::opened($campaignId) === 'sent' ? 1 : 0;
        }

        return $sent;
    }

    /**
     * @return list<int>
     */
    private static function pendingNotices(): array
    {
        return array_map('intval', (array) get_option(self::OPTION_PENDING, []));
    }

    private static function forgetPendingNotice(int $campaignId): void
    {
        $pending = array_values(array_diff(self::pendingNotices(), [$campaignId]));

        if ($pending === []) {
            delete_option(self::OPTION_PENDING);

            return;
        }

        update_option(self::OPTION_PENDING, $pending, false);
    }
}
