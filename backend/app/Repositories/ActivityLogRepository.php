<?php

namespace App\Repositories;

use App\Models\ActivityLog;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ActivityLogRepository
{
    public function create(array $attributes): ActivityLog
    {
        return ActivityLog::create($attributes);
    }

    /**
     * Entries newest first. "from" and "until" are moments; "search" matches what happened,
     * what it was about or who did it.
     *
     * @param  array{user_id?: int|null, section?: string|null, from?: CarbonInterface|null, until?: CarbonInterface|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters['section'] ?? null, fn (Builder $query, string $section) => $query->where('section', $section))
            ->when($filters['from'] ?? null, fn (Builder $query, CarbonInterface $from) => $query->where('created_at', '>=', $from))
            ->when($filters['until'] ?? null, fn (Builder $query, CarbonInterface $until) => $query->where('created_at', '<', $until))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhere('subject_label', 'like', "%{$search}%")
                        ->orWhere('user_name', 'like', "%{$search}%");
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
