<?php

declare(strict_types=1);

namespace Subalcatel\Club\Notifications;

use Subalcatel\Club\Identity\LegalGuardian;
use WP_User;

/**
 * Envoi et journalisation des e-mails.
 *
 * Une seule porte de sortie pour tout ce que le site envoie. Deux raisons :
 * le journal est complet — sans trace, personne ne sait si l'information est
 * partie et le bureau finit par tout renvoyer « au cas où » — et la garde
 * contre les doublons se fait au même endroit.
 */
final class Mailer
{
    /**
     * Envoie un modèle à un membre.
     *
     * @param array<string, string> $variables
     * @param array{entity_type?: string, entity_id?: int, sender_id?: int, once?: bool} $context
     */
    public static function toUser(
        string $templateCode,
        int $userId,
        array $variables = [],
        array $context = [],
        array $headers = [],
    ): bool {
        $user = get_userdata($userId);

        if (!$user instanceof WP_User || $user->user_email === '') {
            return false;
        }

        $sent = self::send($templateCode, $user->user_email, $variables, array_merge($context, [
            'recipient_id' => $userId,
            'recipient'    => $user,
        ]), $headers);

        self::copyToGuardian($templateCode, $userId, $variables, $context);

        return $sent;
    }

    /**
     * Copie au représentant légal d'un mineur.
     *
     * Un adolescent ne renouvellera pas seul son certificat médical : le rappel
     * doit atteindre celui qui prendra rendez-vous chez le médecin. Le bureau
     * choisit les modèles concernés, modèle par modèle.
     *
     * @param array<string, string> $variables
     * @param array<string, mixed> $context
     */
    private static function copyToGuardian(
        string $templateCode,
        int $userId,
        array $variables,
        array $context,
    ): void {
        $template = EmailTemplates::find($templateCode);

        if ($template === null || (int) ($template['copy_guardian'] ?? 0) !== 1) {
            return;
        }

        $guardian = LegalGuardian::of($userId);

        if ($guardian === null) {
            return;
        }

        $user = get_userdata($userId);

        // Le message reste rédigé pour le membre ; on annonce simplement à qui
        // il s'adresse, plutôt que de maintenir un second jeu de modèles.
        $variables['prenom'] = sprintf(
            '%s (message concernant %s)',
            $guardian['name'] !== '' ? $guardian['name'] : 'Madame, Monsieur',
            trim(($user->first_name ?: '') . ' ' . ($user->last_name ?: '')) ?: $user->display_name
        );

        self::send($templateCode, $guardian['email'], $variables, array_merge($context, [
            'recipient_id' => $userId,
            'recipient'    => null,
            // Contexte distinct : la garde anti-doublon ne doit pas confondre
            // l'envoi au mineur et celui au représentant.
            'entity_type'  => ($context['entity_type'] ?? '') . '_guardian',
        ]));
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, mixed> $context
     * @param list<string> $headers En-têtes supplémentaires — une adresse de
     *        réponse, par exemple. Passés explicitement plutôt que par un filtre
     *        `wp_mail` posé le temps de l'envoi : un filtre global s'appliquerait
     *        aussi à tout courriel parti entre-temps.
     */
    public static function send(
        string $templateCode,
        string $email,
        array $variables = [],
        array $context = [],
        array $headers = [],
    ): bool {
        $template = EmailTemplates::find($templateCode);

        if ($template === null || (int) $template['published'] !== 1) {
            return false;
        }

        // Certains envois ne doivent partir qu'une fois par objet : un rappel
        // d'expiration relancé chaque nuit ferait fuir les adhérents.
        if (!empty($context['once']) && self::alreadySent($templateCode, $context)) {
            return false;
        }

        $recipient = $context['recipient'] ?? null;
        $variables = array_merge(self::baseVariables($recipient), $variables);

        $subject = self::render((string) $template['subject'], $variables);
        $body    = self::render((string) $template['body'], $variables);

        $sent = wp_mail($email, $subject, $body, $headers);

        self::log($templateCode, (string) $template['channel'], $email, $subject, $sent, $context);

        return $sent;
    }

    /**
     * Envoi groupé, pour la communication aux inscrits d'un événement.
     *
     * @param list<int> $userIds
     * @param array<string, string> $variables
     * @param array<string, mixed> $context
     * @return int nombre de messages effectivement partis
     */
    public static function toUsers(
        string $templateCode,
        array $userIds,
        array $variables = [],
        array $context = [],
    ): int {
        $sent = 0;

        foreach ($userIds as $userId) {
            if (self::toUser($templateCode, $userId, $variables, $context)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Remplace les {variables} du modèle.
     *
     * Une variable inconnue est laissée telle quelle plutôt que vidée : un
     * « {montant} » visible dans un e-mail se remarque et se corrige, un blanc
     * passe inaperçu.
     *
     * @param array<string, string> $variables
     */
    public static function render(string $template, array $variables): string
    {
        foreach ($variables as $name => $value) {
            $template = str_replace('{' . $name . '}', (string) $value, $template);
        }

        return $template;
    }

    /**
     * @return array<string, string>
     */
    private static function baseVariables(?WP_User $user): array
    {
        return [
            'prenom' => $user?->first_name ?: ($user?->display_name ?? ''),
            'nom'    => $user?->last_name ?? '',
            'club'   => (string) get_bloginfo('name'),
            'site'   => (string) home_url('/'),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function alreadySent(string $templateCode, array $context): bool
    {
        global $wpdb;

        if (empty($context['entity_type']) || empty($context['entity_id'])) {
            return false;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sub_notification_log
             WHERE template_code = %s AND entity_type = %s AND entity_id = %d
               AND status = 'sent'",
            $templateCode,
            (string) $context['entity_type'],
            (int) $context['entity_id']
        )) > 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function log(
        string $templateCode,
        string $channel,
        string $email,
        string $subject,
        bool $sent,
        array $context,
    ): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'sub_notification_log', [
            'template_code'   => $templateCode,
            'channel'         => $channel,
            'recipient_id'    => $context['recipient_id'] ?? null,
            'recipient_email' => $email,
            'subject'         => $subject,
            'entity_type'     => $context['entity_type'] ?? null,
            'entity_id'       => $context['entity_id'] ?? null,
            'sender_id'       => $context['sender_id'] ?? null,
            'status'          => $sent ? 'sent' : 'failed',
            'error'           => $sent ? null : 'wp_mail a refusé l’envoi.',
        ]);
    }

    /**
     * Date du dernier envoi réussi d'un modèle pour un objet donné.
     *
     * Sert aux envois qu'on a le droit de refaire mais rarement l'intention :
     * réannoncer une sortie est légitime — une place s'est libérée, la météo a
     * changé — mais l'organisateur doit voir qu'il l'a déjà fait avant de
     * cliquer une seconde fois.
     */
    public static function lastSentAt(string $templateCode, string $entityType, int $entityId): ?string
    {
        global $wpdb;

        $date = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sent_at) FROM {$wpdb->prefix}sub_notification_log
             WHERE template_code = %s AND entity_type = %s AND entity_id = %d
               AND status = 'sent'",
            $templateCode,
            $entityType,
            $entityId
        ));

        return $date === null ? null : (string) $date;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 100): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sub_notification_log
             ORDER BY sent_at DESC, id DESC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }
}
