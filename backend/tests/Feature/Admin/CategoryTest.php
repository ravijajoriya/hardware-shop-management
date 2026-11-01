<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_guests_cannot_access_categories(): void
    {
        $category = Category::factory()->create();

        $this->getJson('/api/admin/categories')->assertUnauthorized();
        $this->postJson('/api/admin/categories', ['name' => 'Tools'])->assertUnauthorized();
        $this->getJson("/api/admin/categories/{$category->id}")->assertUnauthorized();
        $this->putJson("/api/admin/categories/{$category->id}", ['name' => 'Tools'])->assertUnauthorized();
        $this->deleteJson("/api/admin/categories/{$category->id}")->assertUnauthorized();
    }

    public function test_non_admins_cannot_access_categories(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/categories')->assertForbidden();
        $this->postJson('/api/admin/categories', ['name' => 'Tools'])->assertForbidden();
    }

    public function test_admin_can_list_categories_ordered_by_name(): void
    {
        $this->actingAsAdmin();
        $tools = Category::factory()->create(['name' => 'Tools']);
        Category::factory()->create(['name' => 'Hammers', 'parent_id' => $tools->id]);
        Category::factory()->create(['name' => 'Adhesives']);

        $this->getJson('/api/admin/categories')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Adhesives')
            ->assertJsonPath('data.1.name', 'Hammers')
            ->assertJsonPath('data.1.parent_id', $tools->id)
            ->assertJsonPath('data.2.name', 'Tools')
            ->assertJsonStructure(['data' => [[
                'id', 'parent_id', 'name', 'slug', 'description', 'is_active', 'created_at', 'updated_at',
            ]]]);
    }

    public function test_admin_can_create_a_category_and_the_slug_is_generated(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/categories', ['name' => 'Power Tools'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Power Tools')
            ->assertJsonPath('data.slug', 'power-tools')
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('categories', ['name' => 'Power Tools', 'slug' => 'power-tools']);
    }

    public function test_generated_slug_gets_a_suffix_when_it_is_already_taken(): void
    {
        $this->actingAsAdmin();
        Category::factory()->create(['slug' => 'power-tools']);
        Category::factory()->create(['slug' => 'power-tools-2']);

        $this->postJson('/api/admin/categories', ['name' => 'Power Tools'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'power-tools-3');
    }

    public function test_admin_can_create_an_inactive_subcategory_with_a_custom_slug(): void
    {
        $this->actingAsAdmin();
        $parent = Category::factory()->create();

        $this->postJson('/api/admin/categories', [
            'parent_id' => $parent->id,
            'name' => 'Cordless Drills',
            'slug' => 'drills',
            'description' => 'Battery powered drills.',
            'is_active' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $parent->id)
            ->assertJsonPath('data.slug', 'drills')
            ->assertJsonPath('data.description', 'Battery powered drills.')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_creating_a_category_validates_the_input(): void
    {
        $this->actingAsAdmin();
        Category::factory()->create(['slug' => 'taken']);

        $this->postJson('/api/admin/categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/categories', [
            'parent_id' => 999,
            'name' => str_repeat('a', 256),
            'slug' => 'Not A Slug',
            'is_active' => 'maybe',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id', 'name', 'slug', 'is_active']);

        $this->postJson('/api/admin/categories', ['name' => 'Anything', 'slug' => 'taken'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->assertDatabaseCount('categories', 1);
    }

    public function test_admin_can_view_a_category(): void
    {
        $this->actingAsAdmin();
        $category = Category::factory()->create();

        $this->getJson("/api/admin/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.slug', $category->slug);

        $this->getJson('/api/admin/categories/999')->assertNotFound();
    }

    public function test_admin_can_update_a_category(): void
    {
        $this->actingAsAdmin();
        $parent = Category::factory()->create();
        $category = Category::factory()->create(['slug' => 'old-slug']);

        $this->putJson("/api/admin/categories/{$category->id}", [
            'parent_id' => $parent->id,
            'name' => 'Hand Tools',
            'slug' => 'hand-tools',
            'description' => null,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.parent_id', $parent->id)
            ->assertJsonPath('data.name', 'Hand Tools')
            ->assertJsonPath('data.slug', 'hand-tools')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'parent_id' => $parent->id,
            'slug' => 'hand-tools',
            'is_active' => false,
        ]);
    }

    public function test_update_keeps_the_slug_unless_it_is_cleared(): void
    {
        $this->actingAsAdmin();
        $category = Category::factory()->create(['name' => 'Paint', 'slug' => 'paint']);

        // Slug not mentioned: unchanged, even though the name changed.
        $this->putJson("/api/admin/categories/{$category->id}", ['name' => 'Paints and Coatings'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'paint');

        // Slug sent as its current value: allowed.
        $this->putJson("/api/admin/categories/{$category->id}", ['name' => 'Paints and Coatings', 'slug' => 'paint'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'paint');

        // Slug cleared: rebuilt from the name.
        $this->putJson("/api/admin/categories/{$category->id}", ['name' => 'Paints and Coatings', 'slug' => null])
            ->assertOk()
            ->assertJsonPath('data.slug', 'paints-and-coatings');
    }

    public function test_update_rejects_a_slug_used_by_another_category(): void
    {
        $this->actingAsAdmin();
        Category::factory()->create(['slug' => 'taken']);
        $category = Category::factory()->create();

        $this->putJson("/api/admin/categories/{$category->id}", ['name' => 'Anything', 'slug' => 'taken'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_a_category_cannot_be_moved_under_itself_or_its_descendants(): void
    {
        $this->actingAsAdmin();
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);

        foreach ([$root, $child, $grandchild] as $invalidParent) {
            $this->putJson("/api/admin/categories/{$root->id}", [
                'name' => $root->name,
                'parent_id' => $invalidParent->id,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['parent_id']);
        }

        $this->assertDatabaseHas('categories', ['id' => $root->id, 'parent_id' => null]);

        // Moving up the tree is fine.
        $this->putJson("/api/admin/categories/{$grandchild->id}", [
            'name' => $grandchild->name,
            'parent_id' => $root->id,
        ])->assertOk()->assertJsonPath('data.parent_id', $root->id);
    }

    public function test_admin_can_delete_a_category(): void
    {
        $this->actingAsAdmin();
        $category = Category::factory()->create();

        $this->deleteJson("/api/admin/categories/{$category->id}")->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_category_with_subcategories_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);

        $this->deleteJson("/api/admin/categories/{$parent->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);

        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
    }

    public function test_a_category_with_products_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $this->deleteJson("/api/admin/categories/{$category->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'category' => 'Move the products in this category to another category before deleting it.',
            ]);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }
}
