<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The packs products are bought in and then sold loose from. A shop that already has its
 * units gets these too; a new shop gets them, with all the others, from UnitSeeder.
 */
return new class extends Migration
{
    /**
     * [name, short name]; packs are always counted in whole numbers.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const PACKS = [
        ['Coil', 'coil'],
        ['Reel', 'reel'],
        ['Carton', 'ctn'],
        ['Sack', 'sack'],
        ['Drum', 'drum'],
        ['Tin', 'tin'],
        ['Can', 'can'],
        ['Bucket', 'bkt'],
        ['Gross', 'gross'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A database without units yet is set up by UnitSeeder instead.
        if (! DB::table('units')->exists()) {
            return;
        }

        foreach (self::PACKS as [$name, $shortName]) {
            $taken = DB::table('units')->where('name', $name)->orWhere('short_name', $shortName)->exists();

            if (! $taken) {
                DB::table('units')->insert([
                    'name' => $name,
                    'short_name' => $shortName,
                    'allow_decimal' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations: the packs no product uses are taken out again.
     */
    public function down(): void
    {
        DB::table('units')
            ->whereIn('short_name', array_column(self::PACKS, 1))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('products')
                ->whereColumn('products.unit_id', 'units.id')
                ->orWhereColumn('products.pack_unit_id', 'units.id'))
            ->delete();
    }
};
