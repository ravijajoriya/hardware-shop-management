<?php

namespace App\Models\Concerns;

/**
 * A bill or delivery line entered in packs (2 coils) or loose (5 m). Its "unit_size" is how
 * many loose units one of its unit is, so stock, which is counted loose, moves by the right
 * amount: 2 coils of 90 m take 180 m out.
 *
 * @property string|null $unit_size
 * @property string|null $base_unit
 */
trait HasUnitSize
{
    /**
     * $quantity of this line's unit, in loose units.
     */
    public function inLooseUnits(float|string $quantity): float
    {
        return round((float) $quantity * (float) ($this->unit_size ?? 1), 3);
    }

    /**
     * Whether the line is in packs rather than loose.
     */
    public function isInPacks(): bool
    {
        return (float) ($this->unit_size ?? 1) !== 1.0;
    }

    /**
     * The loose unit stock is counted in, such as "m".
     */
    public function looseUnit(): string
    {
        return $this->base_unit ?? $this->unit;
    }
}
