<?php

declare(strict_types=1);

namespace Subalcatel\Club\Documents;

use RuntimeException;
use Subalcatel\Club\Policy\Decision;
use Subalcatel\Club\Notifications\EmailTemplates;
use Subalcatel\Club\Notifications\Mailer;
use Subalcatel\Club\Support\Audit;

/**
 * Documents personnels : dépôt, validation, consultation, purge.
 *
 * Le principe qui gouverne tout le module : `EligibilityPolicy` n'a jamais
 * besoin d'ouvrir un fichier. Elle lit un statut et une date. La quasi-totalité
 * du site fonctionne donc sans jamais toucher à une donnée de santé — seule une
 * page, réservée à quelques personnes, sait déchiffrer.
 */
final class DocumentService
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_VALID    = 'valid';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_PURGED   = 'purged';

    /** Extensions acceptées : rien d'exécutable, jamais. */
    private const ALLOWED = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];

    private const MAX_BYTES = 8 * 1024 * 1024;

    private string $prefix;

    public function __construct()
    {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'sub_';
    }

    /**
     * Dépose un document. Remplace le précédent du même type, qui est purgé.
     *
     * @param array{tmp_name: string, name: string, type: string, size: int, error: int} $file
     */
    public function upload(int $userId, string $typeSlug, array $file, ?string $issuedOn, int $actorId): int
    {
        global $wpdb;

        $type = DocumentTypes::find($typeSlug);

        if ($type === null || (int) $type['published'] !== 1) {
            throw new RuntimeException('Type de document inconnu.');
        }

        if ($userId !== $actorId && !user_can($actorId, 'sub_manage_memberships')) {
            throw new RuntimeException('Vous ne pouvez déposer que vos propres documents.');
        }

        $this->assertFileIsAcceptable($file);

        $issuedOn   = $this->sanitizeDate($issuedOn) ?: current_time('Y-m-d');
        $validUntil = null;
        $purgeOn    = null;

        if ((int) $type['has_validity'] === 1) {
            $validUntil = (new \DateTimeImmutable($issuedOn))
                ->modify('+' . (int) $type['validity_months'] . ' months')
                ->format('Y-m-d');

            $purgeOn = (new \DateTimeImmutable($validUntil))
                ->modify('+' . (int) $type['purge_delay_days'] . ' days')
                ->format('Y-m-d');
        }

        $encrypt  = (int) $type['encrypted'] === 1;
        $relative = DocumentStorage::store($file, $encrypt);

        // Le précédent document du même type est purgé : on ne conserve pas une
        // pile de certificats médicaux périmés.
        $this->purgePrevious($userId, $typeSlug, $actorId);

        $wpdb->insert("{$this->prefix}member_documents", [
            'user_id'       => $userId,
            'type_slug'     => $typeSlug,
            'file_path'     => $relative,
            'original_name' => sanitize_file_name((string) $file['name']),
            'mime_type'     => (string) $file['type'],
            'file_size'     => (int) $file['size'],
            'is_encrypted'  => $encrypt ? 1 : 0,
            'issued_on'     => $issuedOn,
            'valid_until'   => $validUntil,
            'purge_on'      => $purgeOn,
            'status'        => (int) $type['needs_validation'] === 1
                ? self::STATUS_PENDING
                : self::STATUS_VALID,
        ]);

        $documentId = (int) $wpdb->insert_id;

        // Le nom du fichier n'est pas journalisé : il peut être parlant.
        Audit::log('document.uploaded', 'member_document', $documentId, [
            'type' => $typeSlug,
            'user' => $userId,
        ], $actorId);

        return $documentId;
    }

    /**
     * Valide ou refuse un document déposé.
     */
    public function review(int $documentId, bool $accepted, int $actorId, string $reason = ''): void
    {
        global $wpdb;

        $document = $this->find($documentId);

        if ($document === null) {
            throw new RuntimeException('Document introuvable.');
        }

        if (!user_can($actorId, 'sub_validate_member_document')) {
            throw new RuntimeException('Droit de validation des documents requis.');
        }

        $wpdb->update("{$this->prefix}member_documents", [
            'status'        => $accepted ? self::STATUS_VALID : self::STATUS_REJECTED,
            'reject_reason' => $accepted ? null : $reason,
            'verified_by'   => $actorId,
            'verified_at'   => current_time('mysql'),
        ], ['id' => $documentId]);

        Audit::log(
            $accepted ? 'document.validated' : 'document.rejected',
            'member_document',
            $documentId,
            ['type' => $document['type_slug'], 'user' => (int) $document['user_id']],
            $actorId
        );

        $type = DocumentTypes::find((string) $document['type_slug']);

        Mailer::toUser(
            $accepted ? EmailTemplates::DOCUMENT_VALIDATED : EmailTemplates::DOCUMENT_REJECTED,
            (int) $document['user_id'],
            [
                'document'     => mb_strtolower((string) ($type['label'] ?? 'document')),
                'fin_validite' => self::frDate((string) $document['valid_until']),
                'motif'        => $reason,
            ],
            ['entity_type' => 'member_document', 'entity_id' => $documentId, 'sender_id' => $actorId]
        );
    }

    /**
     * Contenu d'un document, pour la personne habilitée.
     *
     * Chaque consultation d'un type journalisé laisse une trace : c'est ce qui
     * rend l'accès traçable, donc défendable.
     *
     * @return array{name: string, mime: string, body: string}
     */
    public function download(int $documentId, int $actorId): array
    {
        global $wpdb;

        $document = $this->find($documentId);

        if ($document === null || $document['status'] === self::STATUS_PURGED) {
            throw new RuntimeException('Document introuvable.');
        }

        $decision = $this->canView($document, $actorId);

        if (!$decision->allowed) {
            throw new RuntimeException($decision->reason);
        }

        $type = DocumentTypes::find((string) $document['type_slug']);

        if ($type !== null && (int) $type['log_access'] === 1) {
            $wpdb->insert("{$this->prefix}document_access_log", [
                'document_id' => $documentId,
                'actor_id'    => $actorId,
                'action'      => 'view',
                'ip_address'  => filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null,
            ]);
        }

        return [
            'name' => (string) $document['original_name'],
            'mime' => (string) $document['mime_type'],
            'body' => DocumentStorage::read(
                (string) $document['file_path'],
                (int) $document['is_encrypted'] === 1
            ),
        ];
    }

    /**
     * Qui peut ouvrir ce document ?
     *
     * @param array<string, mixed> $document
     */
    public function canView(array $document, int $actorId): Decision
    {
        // Le membre accède toujours à ses propres documents.
        if ((int) $document['user_id'] === $actorId) {
            return Decision::allow();
        }

        $type       = DocumentTypes::find((string) $document['type_slug']);
        $capability = (string) ($type['view_capability'] ?? 'sub_manage_memberships');

        if (user_can($actorId, $capability)) {
            return Decision::allow();
        }

        return Decision::deny('Vous n’avez pas le droit de consulter ce document.');
    }

    /**
     * État des documents d'un membre, du point de vue des règles.
     *
     * Ne lit aucun fichier : c'est ce qui permet à `EligibilityPolicy` de
     * travailler sans jamais approcher une donnée de santé.
     *
     * @return array{missing: list<string>, expired: list<array{label: string, date: string}>, pending: list<string>}
     */
    public function statusFor(int $userId, ?string $onDate = null): array
    {
        $onDate  = $onDate ?? current_time('Y-m-d');
        $result  = ['missing' => [], 'expired' => [], 'pending' => []];

        foreach (DocumentTypes::all() as $type) {
            if ((int) $type['blocks_dives'] !== 1 && !DocumentTypes::isRequiredFor($type, $userId)) {
                continue;
            }

            if (!DocumentTypes::isRequiredFor($type, $userId)) {
                continue;
            }

            $document = $this->latest($userId, (string) $type['slug']);

            if ($document === null || $document['status'] === self::STATUS_PURGED) {
                $result['missing'][] = (string) $type['label'];

                continue;
            }

            if ($document['status'] === self::STATUS_PENDING) {
                $result['pending'][] = (string) $type['label'];
            }

            if ($document['status'] === self::STATUS_REJECTED) {
                $result['missing'][] = (string) $type['label'];

                continue;
            }

            if ($document['valid_until'] !== null && $document['valid_until'] < $onDate) {
                $result['expired'][] = [
                    'label' => (string) $type['label'],
                    'date'  => (string) $document['valid_until'],
                ];
            }
        }

        return $result;
    }

    /**
     * Documents encore valides mais dont l'échéance approche.
     *
     * Distinct de `statusFor()`, qui ne rapporte que les problèmes : ici on
     * anticipe, pour que le membre s'organise plutôt que de subir.
     *
     * @return list<array{label: string, date: string, days: int}>
     */
    public function expiringSoon(int $userId, int $withinDays = 45, ?string $onDate = null): array
    {
        global $wpdb;

        $today = new \DateTimeImmutable($onDate ?? current_time('Y-m-d'));
        $limit = $today->modify('+' . $withinDays . ' days')->format('Y-m-d');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.valid_until, t.label
             FROM {$this->prefix}member_documents d
             LEFT JOIN {$this->prefix}document_types t ON t.slug = d.type_slug
             WHERE d.user_id = %d AND d.status = 'valid'
               AND d.valid_until BETWEEN %s AND %s
             ORDER BY d.valid_until ASC",
            $userId,
            $today->format('Y-m-d'),
            $limit
        ), ARRAY_A) ?: [];

        return array_map(static function (array $row) use ($today): array {
            return [
                'label' => (string) $row['label'],
                'date'  => (string) $row['valid_until'],
                'days'  => (int) $today->diff(new \DateTimeImmutable((string) $row['valid_until']))->days,
            ];
        }, $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latest(int $userId, string $typeSlug): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->prefix}member_documents
             WHERE user_id = %d AND type_slug = %s
             ORDER BY uploaded_at DESC, id DESC LIMIT 1",
            $userId,
            $typeSlug
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $documentId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->prefix}member_documents WHERE id = %d",
            $documentId
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingReview(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT d.*, u.display_name, t.label AS type_label
             FROM {$this->prefix}member_documents d
             LEFT JOIN {$wpdb->users} u ON u.ID = d.user_id
             LEFT JOIN {$this->prefix}document_types t ON t.slug = d.type_slug
             WHERE d.status = 'pending'
             ORDER BY d.uploaded_at ASC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Supprime les fichiers arrivés à échéance de purge.
     *
     * La ligne est conservée, vidée de son fichier : elle atteste que le club a
     * vérifié le document, sans le garder. C'est exactement la trace qui permet
     * de démontrer sa diligence sans conserver de donnée de santé.
     *
     * @return int nombre de fichiers supprimés
     */
    public function purgeDue(?string $onDate = null): int
    {
        global $wpdb;

        $onDate = $onDate ?? current_time('Y-m-d');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}member_documents
             WHERE purge_on IS NOT NULL AND purge_on <= %s
               AND purged_at IS NULL AND status <> 'purged'",
            $onDate
        ), ARRAY_A) ?: [];

        foreach ($rows as $row) {
            DocumentStorage::delete((string) $row['file_path']);

            $wpdb->update("{$this->prefix}member_documents", [
                'status'    => self::STATUS_PURGED,
                'file_path' => '',
                'purged_at' => current_time('mysql'),
            ], ['id' => (int) $row['id']]);

            Audit::log('document.purged', 'member_document', (int) $row['id'], [
                'type' => $row['type_slug'],
                'user' => (int) $row['user_id'],
            ]);
        }

        return count($rows);
    }

    /**
     * Marque comme expirés les documents dont la validité est dépassée.
     */
    public function markExpired(?string $onDate = null): int
    {
        global $wpdb;

        $onDate = $onDate ?? current_time('Y-m-d');

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$this->prefix}member_documents
             SET status = 'expired'
             WHERE status = 'valid' AND valid_until IS NOT NULL AND valid_until < %s",
            $onDate
        ));
    }

    /**
     * Prévient les membres dont un document sera bientôt supprimé.
     *
     * Sans ce message, la suppression annoncée dans les règles de conservation
     * serait une surprise — et le membre perdrait sa seule copie.
     *
     * @return int nombre de messages envoyés
     */
    public function warnBeforePurge(int $daysBefore = 15, ?string $onDate = null): int
    {
        global $wpdb;

        $target = (new \DateTimeImmutable($onDate ?? current_time('Y-m-d')))
            ->modify('+' . $daysBefore . ' days')
            ->format('Y-m-d');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}member_documents
             WHERE purge_on = %s AND purged_at IS NULL AND file_path <> ''",
            $target
        ), ARRAY_A) ?: [];

        $sent = 0;

        foreach ($rows as $row) {
            $type = DocumentTypes::find((string) $row['type_slug']);

            $ok = Mailer::toUser(EmailTemplates::DOCUMENT_PURGED, (int) $row['user_id'], [
                'document'     => mb_strtolower((string) ($type['label'] ?? 'document')),
                'fin_validite' => self::frDate((string) $row['valid_until']),
                'date_purge'   => self::frDate((string) $row['purge_on']),
            ], [
                'entity_type' => 'member_document',
                'entity_id'   => (int) $row['id'],
                'once'        => true,
            ]);

            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }

    public static function frDate(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '') {
            return '';
        }

        $ts = strtotime($isoDate);

        return $ts === false ? $isoDate : wp_date('j F Y', $ts);
    }

    private function purgePrevious(int $userId, string $typeSlug, int $actorId): void
    {
        global $wpdb;

        $previous = $this->latest($userId, $typeSlug);

        if ($previous === null || (string) $previous['file_path'] === '') {
            return;
        }

        DocumentStorage::delete((string) $previous['file_path']);

        $wpdb->update("{$this->prefix}member_documents", [
            'status'    => self::STATUS_PURGED,
            'file_path' => '',
            'purged_at' => current_time('mysql'),
        ], ['id' => (int) $previous['id']]);

        Audit::log('document.replaced', 'member_document', (int) $previous['id'], [
            'type' => $typeSlug,
        ], $actorId);
    }

    /**
     * @param array{tmp_name: string, name: string, type: string, size: int, error: int} $file
     */
    private function assertFileIsAcceptable(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Aucun fichier reçu, ou téléversement interrompu.');
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Le fichier dépasse 8 Mo.');
        }

        $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED[$extension])) {
            throw new RuntimeException('Formats acceptés : PDF, JPG ou PNG.');
        }

        // L'extension ne prouve rien : on vérifie le type réel du contenu.
        // C'est ce contrôle qui empêche un script déguisé en .jpg de passer.
        $detected = wp_check_filetype_and_ext(
            (string) $file['tmp_name'],
            (string) $file['name'],
            self::ALLOWED
        );

        if (empty($detected['ext']) || empty($detected['type'])) {
            throw new RuntimeException('Le contenu du fichier ne correspond pas à son extension.');
        }

        UploadGuard::rejectExecutable((string) $file['tmp_name']);
    }

    private function sanitizeDate(?string $raw): string
    {
        $value = trim((string) $raw);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
