<?php

namespace Tests\Feature\Seeders;

use App\Models\Unit;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_common_units(): void
    {
        $this->seed(UnitSeeder::class);

        $this->assertDatabaseCount('units', 20);
        $this->assertDatabaseHas('units', ['name' => 'Piece', 'short_name' => 'pc', 'allow_decimal' => false, 'is_active' => true]);
        $this->assertDatabaseHas('units', ['name' => 'Kilogram', 'short_name' => 'kg', 'allow_decimal' => true, 'is_active' => true]);
        $this->assertDatabaseHas('units', ['name' => 'Square Foot', 'short_name' => 'sq ft', 'allow_decimal' => true]);
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->seed(UnitSeeder::class);
        $this->seed(UnitSeeder::class);

        $this->assertDatabaseCount('units', 20);
    }

    public function test_it_skips_units_whose_name_or_short_name_is_already_used(): void
    {
        // An admin has already set these up differently.
        $kilo = Unit::factory()->create(['name' => 'Kilo', 'short_name' => 'kg', 'allow_decimal' => false]);
        Unit::factory()->create(['name' => 'Piece', 'short_name' => 'pcs']);

        $this->seed(UnitSeeder::class);

        $this->assertDatabaseCount('units', 20);
        $this->assertDatabaseMissing('units', ['name' => 'Kilogram']);
        $this->assertDatabaseMissing('units', ['short_name' => 'pc']);
        $this->assertFalse($kilo->fresh()->allow_decimal);
    }
}
