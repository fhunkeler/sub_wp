<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

use Subalcatel\Club\Identity\OfficePosition;
use Subalcatel\Club\Identity\ProfileFields;
use Subalcatel\Club\Support\Audit;
use Subalcatel\Club\Support\PdfDocument;

/**
 * Reçu de cotisation et attestation d'adhésion, en PDF.
 *
 * Deux pièces que les adhérents demandaient au trésorier par courriel, une par
 * une : le **reçu**, pour un comité d'entreprise ou une mutuelle qui rembourse
 * une partie de la cotisation, et l'**attestation**, qui certifie qu'on est
 * membre pour la saison. Elles se produisent à la demande, depuis le dossier
 * lui-même — rien n'est stocké, rien ne peut diverger du registre.
 *
 * Elles ne sont délivrées que lorsqu'elles disent vrai :
 *
 *  - le reçu, dès qu'un règlement est enregistré par la trésorerie ;
 *  - l'attestation, une fois l'adhésion activée par le secrétariat.
 *
 * Le reçu **n'est pas un reçu fiscal** (Cerfa 11580). Une cotisation qui ouvre
 * l'accès aux activités du club a une contrepartie : elle n'ouvre pas droit à
 * la réduction d'impôt des dons, et le document le dit, pour qu'un adhérent ne
 * le joigne pas à sa déclaration. Un don, s'il y en a un jour, appellera sa
 * propre pièce.
 */
final class MembershipCertificate
{
    public const RECEIPT     = 'recu';
    public const ATTESTATION = 'attestation';

    public const ACTION = 'sub_membership_certificate';

    private const INK    = [0.11, 0.16, 0.24];
    private const ACCENT = [0.0, 0.36, 0.55];
    private const MUTED  = [0.4, 0.44, 0.5];

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handleDownload']);
    }

    /**
     * Les pièces que ce dossier peut produire aujourd'hui.
     *
     * @param array<string, mixed> $application
     *
     * @return list<string>
     */
    public static function available(array $application): array
    {
        $status = (string) $application['status'];
        $kinds  = [];

        if (in_array($status, [ApplicationService::STATUS_PAYMENT_CONFIRMED, ApplicationService::STATUS_ACTIVE], true)
            && (new ApplicationService())->paidAmount((int) $application['id']) > 0) {
            $kinds[] = self::RECEIPT;
        }

        if ($status === ApplicationService::STATUS_ACTIVE) {
            $kinds[] = self::ATTESTATION;
        }

        return $kinds;
    }

    public static function label(string $kind): string
    {
        return $kind === self::ATTESTATION ? 'Attestation d’adhésion' : 'Reçu de cotisation';
    }

    public static function url(int $applicationId, string $kind): string
    {
        return wp_nonce_url(
            add_query_arg([
                'action'         => self::ACTION,
                'application_id' => $applicationId,
                'kind'           => $kind,
            ], admin_url('admin-post.php')),
            self::ACTION . '_' . $applicationId . '_' . $kind
        );
    }

    /**
     * Le titulaire du dossier, ou le bureau.
     */
    public static function mayDownload(array $application, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return (int) ($application['user_id'] ?? 0) === $userId
            || user_can($userId, 'sub_manage_memberships');
    }

    public static function handleDownload(): void
    {
        $applicationId = isset($_GET['application_id']) ? absint($_GET['application_id']) : 0;
        $kind          = sanitize_key((string) ($_GET['kind'] ?? ''));

        if (!is_user_logged_in()
            || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), self::ACTION . '_' . $applicationId . '_' . $kind)) {
            wp_die('Lien expiré. Rechargez la page « Mon adhésion » et réessayez.', 403);
        }

        $application = (new ApplicationService())->find($applicationId);

        if ($application === null || !self::mayDownload($application, get_current_user_id())) {
            wp_die('Ce dossier ne vous est pas accessible.', 403);
        }

        if (!in_array($kind, self::available($application), true)) {
            wp_die(esc_html(self::unavailableReason($kind)), 409);
        }

        $pdf = self::render($application, $kind);

        Audit::log('membership.certificate_issued', 'application', $applicationId, ['kind' => $kind]);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . self::filename($application, $kind) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');

        echo $pdf; // binaire PDF
        exit;
    }

    public static function filename(array $application, string $kind): string
    {
        return sanitize_file_name(sprintf(
            '%s-%s.pdf',
            $kind === self::ATTESTATION ? 'attestation-adhesion' : 'recu-cotisation',
            (string) $application['reference']
        ));
    }

    /**
     * Le PDF, prêt à envoyer.
     *
     * @param array<string, mixed> $application
     */
    public static function render(array $application, string $kind): string
    {
        $facts = self::facts($application);
        $pdf   = new PdfDocument();
        $pdf->setTitle(self::label($kind) . ' ' . $facts['reference']);

        $left  = 56.0;
        $right = PdfDocument::WIDTH - 56.0;
        $width = $right - $left;

        // --- En-tête : l'émetteur ------------------------------------------
        $pdf->fillRect(0, 0, PdfDocument::WIDTH, 8, self::ACCENT);

        $y = 64.0;
        $pdf->text($left, $y, $facts['club']['name'], 18, true, 'left', self::INK);
        $y += 18;

        foreach (preg_split('/\R/u', $facts['club']['address']) ?: [] as $addressLine) {
            if (trim($addressLine) === '') {
                continue;
            }
            $pdf->text($left, $y, trim($addressLine), 9.5, false, 'left', self::MUTED);
            $y += 13;
        }

        if ($facts['club']['affiliation'] !== '') {
            $pdf->text($left, $y, 'Club affilié à la FFESSM sous le n° ' . $facts['club']['affiliation'], 9.5, false, 'left', self::MUTED);
            $y += 13;
        }

        // --- Titre ---------------------------------------------------------
        $y = max($y + 30, 160.0);
        $pdf->text($left, $y, mb_strtoupper(self::label($kind)), 15, true, 'left', self::ACCENT);
        $pdf->text($right, $y, 'N° ' . $facts['reference'], 10, true, 'right', self::INK);
        $y += 10;
        $pdf->line($left, $y, $right, $y, 1, self::ACCENT);
        $y += 30;

        $y = $kind === self::ATTESTATION
            ? self::attestationBody($pdf, $facts, $left, $y, $width)
            : self::receiptBody($pdf, $facts, $left, $y, $width, $application);

        // --- Signature -----------------------------------------------------
        $y += 28;
        $pdf->text($right, $y, 'Fait le ' . self::frDate(current_time('Y-m-d')), 10, false, 'right', self::INK);
        $y += 18;

        $signer = $kind === self::ATTESTATION ? $facts['president'] : $facts['treasurer'];
        $pdf->text($right, $y, $signer['function'] . ' du club', 10, true, 'right', self::INK);

        if ($signer['name'] !== '') {
            $y += 14;
            $pdf->text($right, $y, $signer['name'], 10, false, 'right', self::INK);
        }

        // --- Pied ----------------------------------------------------------
        $foot = PdfDocument::HEIGHT - 70;
        $pdf->line($left, $foot, $right, $foot);
        $pdf->paragraph(
            $left,
            $foot + 16,
            $width,
            sprintf(
                'Document établi depuis le site du club à partir du dossier %s. '
                . 'Il ne porte pas de signature manuscrite : en cas de doute, '
                . 'le bureau du club en confirme l’authenticité sur simple demande.',
                $facts['reference']
            ),
            7.5
        );

        return $pdf->output();
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function attestationBody(PdfDocument $pdf, array $facts, float $left, float $y, float $width): float
    {
        $signer = $facts['president'];
        $who    = $signer['name'] !== ''
            ? sprintf('Je soussigné·e %s, %s du club %s,', $signer['name'], mb_strtolower($signer['function']), $facts['club']['name'])
            : sprintf('Le bureau du club %s', $facts['club']['name']);

        $born = $facts['member']['birth_date'] !== ''
            ? ', né·e le ' . self::frDate($facts['member']['birth_date'])
            : '';

        $y = $pdf->paragraph($left, $y, $width, sprintf(
            '%s atteste que %s%s, est adhérent·e du club pour la saison %s, '
            . 'du %s au %s inclus, au titre de la formule « %s ».',
            $who,
            $facts['member']['name'],
            $born,
            $facts['campaign'],
            self::frDate($facts['valid_from']),
            self::frDate($facts['valid_until']),
            $facts['plan']
        ), 11);

        $y += 8;
        $y = $pdf->paragraph($left, $y, $width, sprintf(
            'La cotisation correspondante, d’un montant de %s, est réglée.',
            self::euro($facts['paid'])
        ), 11);

        $y += 8;

        return $pdf->paragraph($left, $y, $width, 'Attestation délivrée pour servir et valoir ce que de droit.', 11);
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $application
     */
    private static function receiptBody(PdfDocument $pdf, array $facts, float $left, float $y, float $width, array $application): float
    {
        $right = $left + $width;

        // Le payeur, comme un CE le lit : nom et adresse.
        $pdf->text($left, $y, 'Reçu de', 9, false, 'left', self::MUTED);
        $y += 14;
        $pdf->text($left, $y, $facts['member']['name'], 11, true, 'left', self::INK);

        foreach ($facts['member']['address'] as $addressLine) {
            $y += 13;
            $pdf->text($left, $y, $addressLine, 10, false, 'left', self::INK);
        }

        $y += 30;
        $y = $pdf->paragraph($left, $y, $width, sprintf(
            'Le club %s reconnaît avoir reçu la somme de %s au titre de la cotisation '
            . 'de la saison %s (formule « %s »), adhésion valable du %s au %s.',
            $facts['club']['name'],
            self::euro($facts['paid']),
            $facts['campaign'],
            $facts['plan'],
            self::frDate($facts['valid_from']),
            self::frDate($facts['valid_until'])
        ), 11);

        // --- Détail, ligne à ligne, tel que figé au dépôt ------------------
        $y += 16;
        $pdf->text($left, $y, 'Détail de la cotisation', 10, true, 'left', self::INK);
        $y += 8;
        $pdf->line($left, $y, $right, $y);

        foreach ((new ApplicationService())->lines((int) $application['id']) as $line) {
            $y    += 16;
            $label = (string) $line['label'];

            if (!empty($line['value_label'])) {
                $label .= ' — ' . (string) $line['value_label'];
            }

            $pdf->text($left, $y, self::fit($pdf, $label, $width - 90, 10), 10, false, 'left', self::INK);
            $pdf->text($right, $y, self::euro((float) $line['amount']), 10, false, 'right', self::INK);
        }

        $y += 8;
        $pdf->line($left, $y, $right, $y);
        $y += 16;
        $pdf->text($left, $y, 'Total de la cotisation', 10, true, 'left', self::INK);
        $pdf->text($right, $y, self::euro((float) $application['total_amount']), 10, true, 'right', self::INK);

        // --- Règlements encaissés ------------------------------------------
        $y += 30;
        $pdf->text($left, $y, 'Règlements reçus', 10, true, 'left', self::INK);
        $y += 8;
        $pdf->line($left, $y, $right, $y);

        foreach ($facts['payments'] as $payment) {
            $y += 16;
            $pdf->text($left, $y, sprintf(
                '%s — %s',
                self::frDate((string) $payment['received_on']),
                PaymentMethods::label((string) $payment['method'])
            ), 10, false, 'left', self::INK);
            $pdf->text($right, $y, self::euro((float) $payment['amount']), 10, false, 'right', self::INK);
        }

        $y += 8;
        $pdf->line($left, $y, $right, $y);
        $y += 16;
        $pdf->text($left, $y, 'Total reçu', 10, true, 'left', self::INK);
        $pdf->text($right, $y, self::euro($facts['paid']), 10, true, 'right', self::INK);

        $y += 26;

        return $pdf->paragraph(
            $left,
            $y,
            $width,
            'Ce reçu atteste du règlement d’une cotisation associative. '
            . 'Il ne constitue pas un reçu fiscal au titre des articles 200 et 238 bis '
            . 'du Code général des impôts.',
            8.5
        );
    }

    /**
     * Tout ce que les deux pièces affirment, rassemblé en un seul endroit.
     *
     * @param array<string, mixed> $application
     *
     * @return array<string, mixed>
     */
    public static function facts(array $application): array
    {
        global $wpdb;

        $userId = (int) ($application['user_id'] ?? 0);
        $user   = $userId > 0 ? get_userdata($userId) : false;

        $campaign = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM {$wpdb->prefix}sub_campaigns WHERE id = %d",
            (int) $application['campaign_id']
        ));
        $plan = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM {$wpdb->prefix}sub_plans WHERE id = %d",
            (int) $application['plan_id']
        ));

        $address = [];

        if ($user) {
            foreach (preg_split('/\R/u', ProfileFields::get($userId, 'address')) ?: [] as $line) {
                if (trim($line) !== '') {
                    $address[] = trim($line);
                }
            }

            $city = trim(ProfileFields::get($userId, 'postal_code') . ' ' . ProfileFields::get($userId, 'city'));

            if ($city !== '') {
                $address[] = $city;
            }
        }

        return [
            'reference'   => (string) $application['reference'],
            'club'        => ClubIdentity::all(),
            'campaign'    => $campaign !== '' ? $campaign : '—',
            'plan'        => $plan !== '' ? $plan : '—',
            'valid_from'  => (string) $application['valid_from'],
            'valid_until' => (string) $application['valid_until'],
            'paid'        => (new ApplicationService())->paidAmount((int) $application['id']),
            'payments'    => self::payments((int) $application['id']),
            'member'      => [
                // Un compte effacé au titre du RGPD laisse un dossier qui ne
                // désigne plus personne : la pièce le dit plutôt que d'inventer.
                'name'       => $user ? self::personName($user) : 'Membre dont le compte a été supprimé',
                'birth_date' => $user ? ProfileFields::get($userId, 'birth_date') : '',
                'address'    => $address,
            ],
            'president'   => self::signer(OfficePosition::PRESIDENT),
            'treasurer'   => self::signer(OfficePosition::TRESORIER),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function payments(int $applicationId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT amount, method, received_on FROM {$wpdb->prefix}sub_payments
             WHERE application_id = %d AND status = 'received'
             ORDER BY received_on ASC, id ASC",
            $applicationId
        ), ARRAY_A) ?: [];
    }

    /**
     * @return array{function: string, name: string}
     */
    private static function signer(string $position): array
    {
        $holder = OfficePosition::holder($position);

        return [
            'function' => $position === OfficePosition::PRESIDENT ? 'Président·e' : 'Trésorier·ère',
            'name'     => $holder ? self::personName($holder) : '',
        ];
    }

    private static function personName(\WP_User $user): string
    {
        $name = trim($user->first_name . ' ' . mb_strtoupper($user->last_name));

        // Insécables : un nom ne se coupe pas en fin de ligne.
        return self::keepTogether($name !== '' ? $name : $user->display_name);
    }

    private static function unavailableReason(string $kind): string
    {
        return $kind === self::ATTESTATION
            ? 'L’attestation se délivre une fois l’adhésion validée par le secrétariat.'
            : 'Le reçu se délivre une fois le règlement enregistré par la trésorerie.';
    }

    /**
     * Raccourcit un libellé trop long pour sa colonne, plutôt que de le faire
     * chevaucher le montant.
     */
    private static function fit(PdfDocument $pdf, string $text, float $width, float $size): string
    {
        if ($pdf->width($text, $size) <= $width) {
            return $text;
        }

        while ($text !== '' && $pdf->width($text . '…', $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text) . '…';
    }

    private static function frDate(string $isoDate): string
    {
        $ts = strtotime($isoDate);

        return $isoDate === '' || $ts === false ? '—' : self::keepTogether((string) wp_date('j F Y', $ts));
    }

    private static function keepTogether(string $text): string
    {
        return str_replace(' ', "\u{00A0}", $text);
    }

    private static function euro(float $amount): string
    {
        return number_format($amount, 2, ',', "\u{00A0}") . "\u{00A0}€";
    }
}
