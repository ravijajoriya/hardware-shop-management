<?php

namespace App\Services;

use App\Enums\StorageArea;

/**
 * Builds the code that tells a shopkeeper where a product is kept.
 *
 * The parts run from the biggest place to the smallest, joined by dashes: SHOP or GODOWN,
 * then the floor (GF for the ground floor, 1F, 2F …), then R and the rack, then S and the
 * shelf. So SHOP-GF-R3-S2 is the shop's ground floor, rack 3, shelf 2, and GODOWN-1F is
 * somewhere on the godown's first floor. Every product on the same shelf shares the code.
 */
class ProductLocationService
{
    public const MAX_FLOOR = 20;

    public const MAX_SHELF = 99;

    public function code(StorageArea $area, ?int $floor = null, ?string $rack = null, ?int $shelf = null): string
    {
        $parts = [$area->codeWord()];

        if ($floor !== null) {
            $parts[] = $floor === 0 ? 'GF' : "{$floor}F";
        }

        if ($rack !== null) {
            $parts[] = 'R'.strtoupper($rack);
        }

        if ($shelf !== null) {
            $parts[] = 'S'.$shelf;
        }

        return implode('-', $parts);
    }

    /**
     * Fill in "location_code" from validated location fields. Parts that were not sent are
     * cleared, so a product moved from a shelf to the godown floor loses its old rack and shelf.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withCode(array $data): array
    {
        $data['location_floor'] = isset($data['location_floor']) ? (int) $data['location_floor'] : null;
        $data['location_rack'] = $data['location_rack'] ?? null;
        $data['location_shelf'] = isset($data['location_shelf']) ? (int) $data['location_shelf'] : null;

        $data['location_code'] = $this->code(
            StorageArea::from($data['location_area']),
            $data['location_floor'],
            $data['location_rack'],
            $data['location_shelf'],
        );

        return $data;
    }
}
