<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

/**
 * Accès aux données d'une campagne : plans, options, remises.
 *
 * Seule classe du module qui parle à la base. Les services et les écrans
 * passent par elle — c'est ce qui garde les requêtes préparées au même endroit.
 */
final class CampaignRepository
{
    private string $prefix;

    public function __construct()
    {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'sub_';
    }

    /**
     * Campagne ouverte à une date donnée, ou null.
     *
     * @return array<string, mixed>|null
     */
    public function openCampaign(?string $onDate = null): ?array
    {
        global $wpdb;
        $onDate ??= current_time('Y-m-d');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->prefix}campaigns
                 WHERE status = 'open' AND opens_on <= %s AND closes_on >= %s
                 ORDER BY opens_on DESC LIMIT 1",
                $onDate,
                $onDate
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Campagne à présenter au public : celle qui est ouverte, sinon la plus
     * récente.
     *
     * La page des tarifs doit rester lisible entre deux campagnes. Une grille
     * vide neuf mois par an ne renseigne personne et donne un site à l'abandon ;
     * les tarifs de la saison écoulée donnent au moins un ordre de grandeur, à
     * condition de dire qu'ils ne sont plus en vigueur.
     *
     * @return array{campaign: array<string, mixed>, is_open: bool}|null
     */
    public function campaignToShow(?string $onDate = null): ?array
    {
        $open = $this->openCampaign($onDate);

        if ($open !== null) {
            return ['campaign' => $open, 'is_open' => true];
        }

        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT * FROM {$this->prefix}campaigns
             WHERE status <> 'draft' ORDER BY valid_from DESC, opens_on DESC LIMIT 1",
            ARRAY_A
        );

        return $row ? ['campaign' => $row, 'is_open' => false] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function campaignBySlug(string $slug): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->prefix}campaigns WHERE slug = %s", $slug),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @return list<Plan>
     */
    public function plans(int $campaignId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->prefix}plans
                 WHERE campaign_id = %d AND published = 1
                 ORDER BY ordering ASC, id ASC",
                $campaignId
            ),
            ARRAY_A
        ) ?: [];

        return array_map([Plan::class, 'fromRow'], $rows);
    }

    public function planBySlug(int $campaignId, string $slug): ?Plan
    {
        foreach ($this->plans($campaignId) as $plan) {
            if ($plan->slug === $slug) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @return list<Option>
     */
    public function options(int $campaignId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->prefix}options
                 WHERE campaign_id = %d
                 ORDER BY ordering ASC, id ASC",
                $campaignId
            ),
            ARRAY_A
        ) ?: [];

        return array_map([Option::class, 'fromRow'], $rows);
    }

    /**
     * Les adresses de paiement en ligne de la campagne, mode par mode.
     *
     * Elles sont rattachées à la campagne et non aux réglages du site parce
     * qu'elles en suivent le rythme : HelloAsso ouvre une campagne d'adhésion
     * et une boutique CE Orange par saison, sous une adresse neuve à chaque
     * fois. Un réglage global aurait fait pointer les dossiers de la saison
     * passée vers la page de la saison en cours — et l'inverse pendant la
     * quinzaine où les deux campagnes se chevauchent.
     *
     * @return array<string, string>
     */
    public function paymentLinks(int $campaignId): array
    {
        global $wpdb;

        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT payment_links FROM {$this->prefix}campaigns WHERE id = %d",
            $campaignId
        ));

        return PaymentMethods::decodeLinks($json);
    }

    /**
     * L'adresse d'un mode, ou une chaîne vide s'il n'en a pas.
     */
    public function paymentLink(int $campaignId, string $method): string
    {
        return $this->paymentLinks($campaignId)[$method] ?? '';
    }

    /**
     * @param array<string, string> $links Déjà passées par `PaymentMethods::sanitizeLinks`.
     */
    public function savePaymentLinks(int $campaignId, array $links): void
    {
        global $wpdb;

        $wpdb->update(
            "{$this->prefix}campaigns",
            ['payment_links' => PaymentMethods::encodeLinks($links)],
            ['id' => $campaignId]
        );
    }

    /**
     * @return list<DiscountRule>
     */
    public function discountRules(int $campaignId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->prefix}discount_rules
                 WHERE campaign_id = %d
                 ORDER BY ordering ASC, id ASC",
                $campaignId
            ),
            ARRAY_A
        ) ?: [];

        return array_map([DiscountRule::class, 'fromRow'], $rows);
    }
}
