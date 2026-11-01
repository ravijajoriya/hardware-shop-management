<?php

namespace App\Repositories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;

class UnitRepository
{
    /**
     * @return Collection<int, Unit>
     */
    public function all(): Collection
    {
        return Unit::orderBy('name')->get();
    }

    public function find(int $id): ?Unit
    {
        return Unit::find($id);
    }
}
