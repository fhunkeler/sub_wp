<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Orchestration de la reprise Joomla → WordPress.
 *
 * **Simulation par défaut.** Une reprise s'exécute une fois, sur des données
 * réelles, et se défait mal ; le mode par défaut est donc celui qui n'écrit
 * rien. Il faut demander explicitement l'écriture.
 *
 * L'ordre compte : les comptes d'abord, puisque les adhésions s'y rattachent.
 */
final class JoomlaImport
{
    public function __construct(private readonly LegacySource $source)
    {
    }

    /**
     * @param  list<string> $only sections à traiter ; toutes si vide
     */
    public function run(bool $dryRun = true, array $only = []): Report
    {
        $report = new Report();

        if (!$this->source->isReady()) {
            $report->warn(
                'Base héritée injoignable ou vide : ' .
                ($this->source->lastError() ?: 'aucune table ' . $this->source->table('users') . '.')
            );

            return $report;
        }

        $wanted = static fn (string $section): bool => $only === [] || in_array($section, $only, true);

        $users = new UserImporter($this->source, $report);

        // Les adhésions ont besoin de la correspondance des comptes. Quand on ne
        // rejoue que les adhésions, on la reconstruit depuis les comptes déjà
        // importés plutôt que de la recalculer.
        $userMap = $wanted('comptes')
            ? $users->run($dryRun)
            : $this->existingUserMap();

        if ($wanted('adhesions')) {
            (new MembershipImporter($this->source, $report))->run($userMap, $dryRun);
        }

        if ($wanted('articles')) {
            (new ArticleImporter($this->source, $report))->run($dryRun);
        }

        return $report;
    }

    /**
     * Correspondance Joomla → WordPress reconstruite depuis les marques posées
     * lors d'un import précédent.
     *
     * @return array<int, int>
     */
    private function existingUserMap(): array
    {
        $map = [];

        foreach (get_users(['meta_key' => UserImporter::JOOMLA_ID_META, 'fields' => ['ID']]) as $user) {
            $legacy = (int) get_user_meta((int) $user->ID, UserImporter::JOOMLA_ID_META, true);
            if ($legacy > 0) {
                $map[$legacy] = (int) $user->ID;
            }
        }

        return $map;
    }
}
