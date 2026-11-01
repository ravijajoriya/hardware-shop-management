<?php

namespace Tests\Unit;

use App\Enums\StorageArea;
use App\Services\ProductLocationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductLocationServiceTest extends TestCase
{
    /**
     * @return array<string, array{StorageArea, int|null, string|null, int|null, string}>
     */
    public static function places(): array
    {
        return [
            'shop, nothing more' => [StorageArea::Shop, null, null, null, 'SHOP'],
            'godown ground floor' => [StorageArea::Godown, 0, null, null, 'GODOWN-GF'],
            'shop ground floor, rack 3, shelf 2' => [StorageArea::Shop, 0, '3', 2, 'SHOP-GF-R3-S2'],
            'godown 1st floor, rack 12, shelf 4' => [StorageArea::Godown, 1, '12', 4, 'GODOWN-1F-R12-S4'],
            'shop 2nd floor, rack a' => [StorageArea::Shop, 2, 'a', null, 'SHOP-2F-RA'],
        ];
    }

    #[DataProvider('places')]
    public function test_it_builds_the_code_from_the_biggest_place_to_the_smallest(
        StorageArea $area,
        ?int $floor,
        ?string $rack,
        ?int $shelf,
        string $code,
    ): void {
        $this->assertSame($code, (new ProductLocationService)->code($area, $floor, $rack, $shelf));
    }

    public function test_it_fills_in_the_code_and_clears_parts_that_were_not_sent(): void
    {
        $data = (new ProductLocationService)->withCode([
            'name' => 'Cement',
            'location_area' => 'godown',
            'location_floor' => '0',
        ]);

        $this->assertSame([
            'name' => 'Cement',
            'location_area' => 'godown',
            'location_floor' => 0,
            'location_rack' => null,
            'location_shelf' => null,
            'location_code' => 'GODOWN-GF',
        ], $data);
    }
}
