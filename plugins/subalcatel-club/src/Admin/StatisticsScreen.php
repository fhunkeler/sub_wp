<?php

declare(strict_types=1);

namespace Subalcatel\Club\Admin;

/**
 * Club → Statistiques.
 *
 * L'écran qu'on ouvre avant une assemblée générale, un dossier de subvention
 * ou un budget prévisionnel — trois ou quatre fois l'an. C'est pourquoi il est
 * ici et non sur le tableau de bord : ces chiffres n'appellent aucune action
 * dans la journée, et les mêler aux files d'attente ferait perdre les deux.
 *
 * Deux onglets parce que deux publics : la vie du club d'un côté, l'argent de
 * l'autre. Un onglet dont la personne n'a pas la capacité n'est pas affiché —
 * `AdminUi::tabbedScreen` s'en charge, et l'entrée de menu disparaît quand il
 * ne reste rien.
 */
final class StatisticsScreen
{
    public const SLUG = 'subalcatel-statistiques';

    public const TAB_CLUB     = 'club';
    public const TAB_FINANCES = 'finances';

    /** @var list<string> */
    public const CAPABILITIES = [
        'sub_manage_memberships',
        'sub_export_payments',
    ];

    public static function render(): void
    {
        AdminUi::tabbedScreen(self::SLUG, 'Statistiques', [
            self::TAB_CLUB => [
                'label'  => 'Vie du club',
                'cap'    => 'sub_manage_memberships',
                'render' => [self::class, 'renderClub'],
            ],
            self::TAB_FINANCES => [
                'label'  => 'Finances',
                'cap'    => 'sub_export_payments',
                'render' => [self::class, 'renderFinances'],
            ],
        ]);
    }

    public static function renderClub(): void
    {
        echo '<p class="description">Ces chiffres décrivent l’effectif à jour d’adhésion,
              à la date d’aujourd’hui. Ils se lisent une fois par saison.</p>';

        echo '<div class="sub-charts">';
        self::renewal();
        self::diveLevels();
        self::ageBands();
        self::participation();
        echo '</div>';
    }

    public static function renderFinances(): void
    {
        echo '<p class="description">Montants réellement facturés, lignes de dossier à
              l’appui — pas les tarifs du catalogue.</p>';

        echo '<div class="sub-charts">';
        self::revenue();
        self::membershipOrigin();
        self::optionRevenue();
        self::paymentDelay();
        echo '</div>';
    }

    // --- Vie du club -------------------------------------------------------

    private static function renewal(): void
    {
        $data = AnnualCharts::renewal();

        ChartUi::open('Renouvellement', $data['previous'] === null
            ? ''
            : sprintf('%s → %s', $data['previous'], (string) $data['current']));

        if ($data['previous'] === null || $data['base'] === 0) {
            ChartUi::emptyState('Le renouvellement se mesure d’une campagne à la suivante :
                il s’affichera dès que deux campagnes auront été menées sur ce site.');
            ChartUi::close();

            return;
        }

        $rows = [
            ['label' => 'Ont renouvelé', 'count' => $data['renewed'], 'tone' => 'full'],
            ['label' => 'Non revenus', 'count' => $data['lost'], 'tone' => 'low'],
            ['label' => 'Nouveaux adhérents', 'count' => $data['newcomers'], 'tone' => 'ok'],
        ];

        $peak = ChartUi::peak($rows);

        ChartUi::bars(array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'value' => (string) $row['count'],
            'ratio' => $row['count'] / $peak,
            'tone'  => $row['tone'],
        ], $rows));

        ChartUi::note(sprintf(
            '<strong>%d %%</strong> des %d adhérents de « %s » ont repris leur adhésion.
             Le club en a gagné %d nouveaux.',
            (int) round(100 * $data['renewed'] / $data['base']),
            $data['base'],
            esc_html((string) $data['previous']),
            $data['newcomers']
        ));

        ChartUi::close();
    }

    private static function diveLevels(): void
    {
        $data = AnnualCharts::diveLevels();

        ChartUi::open('Niveaux de plongée', sprintf('%d adhérents à jour', $data['total']));

        if ($data['rows'] === []) {
            ChartUi::emptyState('Aucun adhérent à jour d’adhésion. La répartition par niveau
                apparaîtra dès la première adhésion active.');
            ChartUi::close();

            return;
        }

        $peak = ChartUi::peak($data['rows']);

        ChartUi::bars(array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'value' => (string) $row['count'],
            'ratio' => $row['count'] / $peak,
        ], $data['rows']));

        ChartUi::close();
    }

    private static function ageBands(): void
    {
        $data = AnnualCharts::ageBands();

        ChartUi::open('Tranches d’âge', sprintf('%d adhérents à jour', $data['total']));

        if ($data['total'] === 0) {
            ChartUi::emptyState('Aucun adhérent à jour d’adhésion. Les tranches se
                rempliront à partir des dates de naissance des fiches.');
            ChartUi::close();

            return;
        }

        $peak = ChartUi::peak($data['rows']);

        ChartUi::bars(array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'value' => (string) $row['count'],
            'ratio' => $row['count'] / $peak,
        ], $data['rows']));

        if ($data['unknown'] > 0) {
            ChartUi::note(sprintf(
                '%d fiche%s sans date de naissance exploitable — hors répartition.',
                $data['unknown'],
                $data['unknown'] > 1 ? 's' : ''
            ));
        }

        ChartUi::close();
    }

    private static function participation(): void
    {
        $data = AnnualCharts::participation();

        ChartUi::open('Participation aux sorties', '12 derniers mois');

        if ($data['total'] === 0) {
            ChartUi::emptyState('Aucun adhérent à jour d’adhésion. Cette répartition
                comptera les sorties passées de chacun sur douze mois.');
            ChartUi::close();

            return;
        }

        $peak = ChartUi::peak($data['rows']);

        ChartUi::bars(array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'value' => (string) $row['count'],
            'ratio' => $row['count'] / $peak,
            'tone'  => $row['label'] === 'Aucune sortie' ? 'low' : '',
        ], $data['rows']));

        $absent = (int) ($data['rows'][0]['count'] ?? 0);

        if ($absent > 0) {
            ChartUi::note(sprintf(
                '<strong>%d adhérent%s à jour</strong> n’%s participé à aucune sortie depuis
                 un an, soit %d %% de l’effectif.',
                $absent,
                $absent > 1 ? 's' : '',
                $absent > 1 ? 'ont' : 'a',
                (int) round(100 * $absent / $data['total'])
            ));
        }

        ChartUi::close();
    }

    // --- Finances ----------------------------------------------------------

    private static function revenue(): void
    {
        $data = AnnualCharts::revenue();

        ChartUi::open('Recettes par nature', $data['campaign'] ?? '');

        if ($data['campaign'] === null || $data['files'] === 0) {
            ChartUi::emptyState('Aucun dossier facturé. Ce graphique montrera ce que pèsent
                respectivement les formules, les options et les remises.');
            ChartUi::close();

            return;
        }

        $peak = ChartUi::peak($data['rows'], 'amount');

        ChartUi::bars(array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'value' => AdminUi::euro($row['amount']),
            'ratio' => abs($row['amount']) / $peak,
            // Une remise est un montant négatif : la barre en garde la
            // longueur, la couleur et le signe disent le sens.
            'tone'  => $row['amount'] < 0 ? 'full' : 'ok',
        ], $data['rows']));

        ChartUi::note(sprintf(
            'Total facturé sur %d dossier%s : <strong>%s</strong>.',
            $data['files'],
            $data['files'] > 1 ? 's' : '',
            AdminUi::euro($data['total'])
        ));

        ChartUi::close();
    }

    private static function membershipOrigin(): void
    {
        $data = AnnualCharts::membershipOrigin();

        ChartUi::open('Origine des adhésions', $data['campaign'] ?? '');

        if (!$data['asked']) {
            ChartUi::emptyState('Cette campagne ne pose pas de question d’origine.
                Ajoutez l’option « Origine de l’adhésion » depuis la configuration de la
                campagne pour savoir quelle part de la recette tient au lien avec
                l’entreprise.');
            ChartUi::close();

            return;
        }

        if ($data['known'] === 0) {
            ChartUi::emptyState('Aucun dossier de cette campagne ne renseigne son origine.
                La répartition apparaîtra dès les premières adhésions déposées par le
                formulaire.');
            ChartUi::close();

            return;
        }

        $peak = ChartUi::peak($data['rows'], 'amount');

        ChartUi::bars(array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'meta'  => sprintf('%d dossier%s', $row['files'], $row['files'] > 1 ? 's' : ''),
            'value' => AdminUi::euro($row['amount']),
            'ratio' => $row['amount'] / $peak,
        ], $data['rows']));

        ChartUi::note(sprintf(
            'Réparti sur <strong>%d dossier%s</strong> qui renseignent leur origine.',
            $data['known'],
            $data['known'] > 1 ? 's' : ''
        ));

        // Les dossiers repris du Joomla n'ont pas de formulaire derrière eux.
        // Les taire ferait lire les proportions ci-dessus comme celles du club
        // entier, alors qu'elles ne portent parfois que sur une poignée.
        if ($data['unknown'] > 0) {
            ChartUi::note(sprintf(
                '%d dossier%s sans origine connue (%s), repris ou saisis hors formulaire —
                 hors répartition.',
                $data['unknown'],
                $data['unknown'] > 1 ? 's' : '',
                AdminUi::euro($data['unknown_amount'])
            ));
        }

        ChartUi::close();
    }

    private static function optionRevenue(): void
    {
        $data = AnnualCharts::optionRevenue();

        ChartUi::open('Ce que pèsent les options', $data['campaign'] ?? '');

        if ($data['rows'] === []) {
            ChartUi::emptyState('Aucune option souscrite. Ce classement dira lesquelles
                rapportent, et lesquelles ne sont jamais choisies.');
            ChartUi::close();

            return;
        }

        $peak = ChartUi::peak($data['rows'], 'amount');

        ChartUi::bars(array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'meta'  => sprintf('%d souscription%s', $row['count'], $row['count'] > 1 ? 's' : ''),
            'value' => AdminUi::euro($row['amount']),
            'ratio' => $row['amount'] / $peak,
        ], $data['rows']));

        ChartUi::close();
    }

    private static function paymentDelay(): void
    {
        $data = AnnualCharts::paymentDelay();

        ChartUi::open('Délai d’encaissement', 'du dépôt du dossier au règlement');

        if ($data['total'] === 0) {
            ChartUi::emptyState('Aucun règlement enregistré. Ce graphique mesurera le temps
                écoulé entre le dépôt d’un dossier et son encaissement.');
            ChartUi::close();

            return;
        }

        $peak = ChartUi::peak($data['rows']);

        ChartUi::bars(array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'value' => (string) $row['count'],
            'ratio' => $row['count'] / $peak,
        ], $data['rows']));

        ChartUi::note(sprintf(
            'Délai médian : <strong>%d jour%s</strong>, sur %d règlement%s.',
            (int) $data['median'],
            (int) $data['median'] > 1 ? 's' : '',
            $data['total'],
            $data['total'] > 1 ? 's' : ''
        ));

        ChartUi::close();
    }
}
