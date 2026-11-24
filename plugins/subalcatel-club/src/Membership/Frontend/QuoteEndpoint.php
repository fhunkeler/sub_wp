<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership\Frontend;

use Subalcatel\Club\Membership\CampaignRepository;
use Subalcatel\Club\Membership\ThirdPartyShare;
use Subalcatel\Club\Membership\PricingEngine;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Calcul du devis en temps réel, pour l'affichage.
 *
 * Cette route sert le confort d'affichage. Elle ne décide de rien : le montant
 * facturé est celui que recalcule ApplicationService à la soumission.
 */
final class QuoteEndpoint
{
    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route('subalcatel/v1', '/quote', [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
                'args'                => [
                    'campaign_id' => ['required' => true, 'sanitize_callback' => 'absint'],
                    'plan'        => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                ],
            ]);
        });
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $campaignId = (int) $request->get_param('campaign_id');
        $planSlug   = (string) $request->get_param('plan');

        $repo = new CampaignRepository();
        $plan = $repo->planBySlug($campaignId, $planSlug);

        if ($plan === null) {
            return new WP_REST_Response(['message' => 'Plan inconnu.'], 404);
        }

        $answers = [];
        foreach ((array) $request->get_param('options') as $name => $value) {
            $key = sanitize_key((string) $name);

            $answers[$key] = is_array($value)
                ? array_map('sanitize_text_field', $value)
                : sanitize_text_field((string) $value);
        }

        $options = $repo->options($campaignId);
        $rules   = $repo->discountRules($campaignId);
        $quote   = (new PricingEngine())->calculate($plan, $answers, $options, $rules);

        // Le serveur dit quelles options s'affichent : la règle de visibilité
        // n'est pas dupliquée dans le JavaScript. Elle se juge sur les réponses
        // telles que le moteur les retient — case décochée ramenée à « non »,
        // option automatique déjà répondue.
        $resolved = PricingEngine::resolveAnswers($plan, $answers, $options);
        $visible  = [];

        foreach ($options as $option) {
            if ($option->appliesToPlan($plan->slug) && $option->isVisible($resolved)) {
                $visible[] = $option->name;
            }
        }

        // Réglé par un tiers payeur, le total reste celui que le club
        // encaisse : on dit en plus ce que le tiers prend, et ce qui reste à
        // l'adhérent. Les intitulés suivent le tiers déclaré par le mode.
        $share      = null;
        $thirdParty = ThirdPartyShare::forMethod(sanitize_key((string) $request->get_param('payment_method')));

        if ($thirdParty !== null) {
            $split = $thirdParty->fromQuote($quote);
            $share = [
                'payer'          => $split['payer'],
                'member'         => $split['member'],
                'payer_display'  => number_format($split['payer'], 2, ',', ' ') . ' €',
                'member_display' => number_format($split['member'], 2, ',', ' ') . ' €',
                'payer_label'    => $thirdParty->payerLine(),
                'help'           => $thirdParty->memberHelp(),
            ];
        }

        return new WP_REST_Response([
            'share'   => $share,
            'lines'   => array_map(
                static fn (array $l): array => [
                    'type'   => $l['type'],
                    'label'  => $l['label'],
                    'value'  => $l['value'],
                    'amount' => $l['amount'],
                    'display' => number_format((float) $l['amount'], 2, ',', ' ') . ' €',
                ],
                $quote->toArray()
            ),
            'total'   => $quote->total(),
            'display' => number_format($quote->total(), 2, ',', ' ') . ' €',
            'visible' => $visible,
        ]);
    }
}
