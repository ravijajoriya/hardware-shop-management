<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_guests_cannot_access_brands(): void
    {
        $brand = Brand::factory()->create();

        $this->getJson('/api/admin/brands')->assertUnauthorized();
        $this->postJson('/api/admin/brands', ['name' => 'Bosch'])->assertUnauthorized();
        $this->getJson("/api/admin/brands/{$brand->id}")->assertUnauthorized();
        $this->putJson("/api/admin/brands/{$brand->id}", ['name' => 'Bosch'])->assertUnauthorized();
        $this->deleteJson("/api/admin/brands/{$brand->id}")->assertUnauthorized();
    }

    public function test_non_admins_cannot_access_brands(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/brands')->assertForbidden();
        $this->postJson('/api/admin/brands', ['name' => 'Bosch'])->assertForbidden();
    }

    public function test_admin_can_list_brands_ordered_by_name(): void
    {
        $this->actingAsAdmin();
        Brand::factory()->create(['name' => 'Stanley']);
        Brand::factory()->create(['name' => 'Asian Paints']);
        Brand::factory()->create(['name' => 'Bosch']);

        $this->getJson('/api/admin/brands')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Asian Paints')
            ->assertJsonPath('data.1.name', 'Bosch')
            ->assertJsonPath('data.2.name', 'Stanley')
            ->assertJsonStructure(['data' => [[
                'id', 'name', 'slug', 'description', 'is_active', 'created_at', 'updated_at',
            ]]]);
    }

    public function test_admin_can_create_a_brand_and_the_slug_is_generated(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/brands', ['name' => 'Black & Decker'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Black & Decker')
            ->assertJsonPath('data.slug', 'black-decker')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('brands', ['name' => 'Black & Decker', 'slug' => 'black-decker']);
    }

    public function test_generated_slug_gets_a_suffix_when_it_is_already_taken(): void
    {
        $this->actingAsAdmin();
        Brand::factory()->create(['name' => 'Bosch Professional', 'slug' => 'bosch']);

        $this->postJson('/api/admin/brands', ['name' => 'Bosch'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'bosch-2');
    }

    public function test_admin_can_create_an_inactive_brand_with_a_custom_slug(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/brands', [
            'name' => 'Asian Paints',
            'slug' => 'asianpaints',
            'description' => 'Paints and coatings.',
            'is_active' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'asianpaints')
            ->assertJsonPath('data.description', 'Paints and coatings.')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_creating_a_brand_validates_the_input(): void
    {
        $this->actingAsAdmin();
        Brand::factory()->create(['name' => 'Bosch', 'slug' => 'bosch']);

        $this->postJson('/api/admin/brands', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/brands', [
            'name' => str_repeat('a', 256),
            'slug' => 'Not A Slug',
            'description' => str_repeat('a', 1001),
            'is_active' => 'maybe',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'slug', 'description', 'is_active']);

        $this->postJson('/api/admin/brands', ['name' => 'Bosch', 'slug' => 'bosch'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name' => 'A brand with this name already exists.',
                'slug' => 'The slug has already been taken.',
            ]);

        $this->assertDatabaseCount('brands', 1);
    }

    public function test_admin_can_view_a_brand(): void
    {
        $this->actingAsAdmin();
        $brand = Brand::factory()->create();

        $this->getJson("/api/admin/brands/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $brand->id)
            ->assertJsonPath('data.slug', $brand->slug);

        $this->getJson('/api/admin/brands/999')->assertNotFound();
    }

    public function test_admin_can_update_a_brand(): void
    {
        $this->actingAsAdmin();
        $brand = Brand::factory()->create(['slug' => 'old-slug']);

        $this->putJson("/api/admin/brands/{$brand->id}", [
            'name' => 'Stanley',
            'slug' => 'stanley-tools',
            'description' => null,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Stanley')
            ->assertJsonPath('data.slug', 'stanley-tools')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'name' => 'Stanley',
            'slug' => 'stanley-tools',
            'is_active' => false,
        ]);
    }

    public function test_update_keeps_the_slug_unless_it_is_cleared(): void
    {
        $this->actingAsAdmin();
        $brand = Brand::factory()->create(['name' => 'Havells', 'slug' => 'havells']);

        // Slug not mentioned: unchanged, even though the name changed.
        $this->putJson("/api/admin/brands/{$brand->id}", ['name' => 'Havells India'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'havells');

        // Name and slug sent as their current values: allowed.
        $this->putJson("/api/admin/brands/{$brand->id}", ['name' => 'Havells India', 'slug' => 'havells'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'havells');

        // Slug cleared: rebuilt from the name.
        $this->putJson("/api/admin/brands/{$brand->id}", ['name' => 'Havells India', 'slug' => null])
            ->assertOk()
            ->assertJsonPath('data.slug', 'havells-india');
    }

    public function test_update_rejects_a_name_or_slug_used_by_another_brand(): void
    {
        $this->actingAsAdmin();
        Brand::factory()->create(['name' => 'Bosch', 'slug' => 'bosch']);
        $brand = Brand::factory()->create();

        $this->putJson("/api/admin/brands/{$brand->id}", ['name' => 'Bosch'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->putJson("/api/admin/brands/{$brand->id}", ['name' => 'Something Else', 'slug' => 'bosch'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_admin_can_delete_a_brand(): void
    {
        $this->actingAsAdmin();
        $brand = Brand::factory()->create();

        $this->deleteJson("/api/admin/brands/{$brand->id}")->assertNoContent();

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    }
}
