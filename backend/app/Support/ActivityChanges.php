<?php

namespace App\Support;

/**
 * Field-by-field differences between two snapshots of a record, for the activity log.
 *
 * A snapshot maps a stable key to the field's label and its value as people read it:
 * ['variant.4.selling_price' => ['label' => 'Selling price (1 L)', 'value' => '₹360.00']].
 * An empty snapshot stands for a record that did not exist (yet, or any more).
 */
final class ActivityChanges
{
    /**
     * @param  array<string, array{label: string, value: string|null}>  $before
     * @param  array<string, array{label: string, value: string|null}>  $after
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];

        foreach (array_keys($after + $before) as $key) {
            $old = $before[$key]['value'] ?? null;
            $new = $after[$key]['value'] ?? null;

            if ($old !== $new) {
                $changes[] = ['field' => ($after[$key] ?? $before[$key])['label'], 'old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * The first few changes in a line: "Selling price ₹350.00 → ₹380.00, Location … and 2 more".
     *
     * @param  list<array{field: string, old: string|null, new: string|null}>  $changes
     */
    public static function summary(array $changes, int $shown = 3): string
    {
        $parts = array_map(
            fn (array $change) => "{$change['field']} ".($change['old'] ?? '—').' → '.($change['new'] ?? '—'),
            array_slice($changes, 0, $shown),
        );
        $more = count($changes) - $shown;

        return implode(', ', $parts).($more > 0 ? " and {$more} more" : '');
    }
}
