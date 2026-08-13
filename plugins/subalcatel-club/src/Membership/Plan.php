<?php

declare(strict_types=1);

namespace Subalcatel\Club\Membership;

final class Plan
{
    public function __construct(
        public readonly int $id,
        public readonly int $campaignId,
        public readonly string $title,
        public readonly string $slug,
        public readonly float $basePrice,
        public readonly string $description = '',
        public readonly int $ordering = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            campaignId: (int) $row['campaign_id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            basePrice: (float) $row['base_price'],
            description: (string) ($row['description'] ?? ''),
            ordering: (int) ($row['ordering'] ?? 0),
        );
    }
}
