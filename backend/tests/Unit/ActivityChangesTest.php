<?php

namespace Tests\Unit;

use App\Support\ActivityChanges;
use PHPUnit\Framework\TestCase;

class ActivityChangesTest extends TestCase
{
    private function field(string $label, ?string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    public function test_only_fields_that_differ_are_listed_in_the_order_they_now_appear(): void
    {
        $before = [
            'name' => $this->field('Name', 'Wall Paint'),
            'price' => $this->field('Selling price', '₹360.00'),
            'old_size' => $this->field('Size name', '4 L'),
        ];
        $after = [
            'name' => $this->field('Name', 'Wall Paint'),
            'price' => $this->field('Selling price', '₹380.00'),
            'new_size' => $this->field('Size name', '10 L'),
        ];

        $this->assertSame([
            ['field' => 'Selling price', 'old' => '₹360.00', 'new' => '₹380.00'],
            ['field' => 'Size name', 'old' => null, 'new' => '10 L'],
            ['field' => 'Size name', 'old' => '4 L', 'new' => null],
        ], ActivityChanges::between($before, $after));
    }

    public function test_a_new_or_removed_record_lists_every_field(): void
    {
        $fields = ['name' => $this->field('Name', 'Tools'), 'parent' => $this->field('Parent category', null)];

        $this->assertSame([['field' => 'Name', 'old' => null, 'new' => 'Tools']], ActivityChanges::between([], $fields));
        $this->assertSame([['field' => 'Name', 'old' => 'Tools', 'new' => null]], ActivityChanges::between($fields, []));
        $this->assertSame([], ActivityChanges::between($fields, $fields));
    }

    public function test_the_summary_shows_the_first_few_changes(): void
    {
        $changes = [
            ['field' => 'Selling price', 'old' => '₹360.00', 'new' => '₹380.00'],
            ['field' => 'Brand', 'old' => null, 'new' => 'Asian Paints'],
            ['field' => 'Active', 'old' => 'Yes', 'new' => 'No'],
            ['field' => 'MRP', 'old' => '₹399.00', 'new' => '₹420.00'],
            ['field' => 'SKU', 'old' => 'AP-1', 'new' => 'AP-2'],
        ];

        $this->assertSame(
            'Selling price ₹360.00 → ₹380.00, Brand — → Asian Paints, Active Yes → No and 2 more',
            ActivityChanges::summary($changes),
        );
        $this->assertSame('Selling price ₹360.00 → ₹380.00', ActivityChanges::summary(array_slice($changes, 0, 1)));
    }
}
