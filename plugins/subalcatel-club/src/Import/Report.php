<?php

declare(strict_types=1);

namespace Subalcatel\Club\Import;

/**
 * Compte rendu d'une reprise.
 *
 * Une migration silencieuse est une migration invérifiable. On garde donc trace
 * de ce qui est entré, de ce qui a été écarté **et pourquoi**, afin que le
 * bureau puisse relire le résultat sans avoir à faire confiance sur parole.
 */
final class Report
{
    /** @var array<string, list<array{id: int|string, detail: string}>> */
    private array $added = [];

    /** @var array<string, list<array{id: int|string, reason: string}>> */
    private array $skipped = [];

    /** @var list<string> */
    private array $warnings = [];

    public function add(string $section, int|string $id, string $detail = ''): void
    {
        $this->added[$section][] = ['id' => $id, 'detail' => $detail];
    }

    public function skip(string $section, int|string $id, string $reason): void
    {
        $this->skipped[$section][] = ['id' => $id, 'reason' => $reason];
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function countAdded(string $section): int
    {
        return count($this->added[$section] ?? []);
    }

    public function countSkipped(string $section): int
    {
        return count($this->skipped[$section] ?? []);
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<string> */
    public function sections(): array
    {
        return array_values(array_unique([
            ...array_keys($this->added),
            ...array_keys($this->skipped),
        ]));
    }

    /** @return list<array{id: int|string, reason: string}> */
    public function skippedIn(string $section): array
    {
        return $this->skipped[$section] ?? [];
    }

    /** @return list<array{id: int|string, detail: string}> */
    public function addedIn(string $section): array
    {
        return $this->added[$section] ?? [];
    }
}
