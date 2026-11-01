<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    /**
     * Common units for a hardware shop: [name, short name, allows decimal quantities].
     *
     * @var list<array{0: string, 1: string, 2: bool}>
     */
    private const UNITS = [
        ['Piece', 'pc', false],
        ['Pair', 'pair', false],
        ['Set', 'set', false],
        ['Dozen', 'dz', false],
        ['Box', 'box', false],
        ['Packet', 'pkt', false],
        ['Bag', 'bag', false],
        ['Roll', 'roll', false],
        ['Sheet', 'sheet', false],
        ['Bundle', 'bdl', false],
        ['Kilogram', 'kg', true],
        ['Gram', 'g', true],
        ['Litre', 'ltr', true],
        ['Millilitre', 'ml', true],
        ['Metre', 'm', true],
        ['Centimetre', 'cm', true],
        ['Millimetre', 'mm', true],
        ['Foot', 'ft', true],
        ['Inch', 'in', true],
        ['Square Foot', 'sq ft', true],
    ];

    /**
     * Add the common units that are missing. A unit whose name or short name is
     * already in use is skipped, so re-running never duplicates or overwrites.
     */
    public function run(): void
    {
        foreach (self::UNITS as [$name, $shortName, $allowDecimal]) {
            $taken = Unit::where('name', $name)->orWhere('short_name', $shortName)->exists();

            if ($taken) {
                continue;
            }

            Unit::create([
                'name' => $name,
                'short_name' => $shortName,
                'allow_decimal' => $allowDecimal,
            ]);
        }
    }
}
