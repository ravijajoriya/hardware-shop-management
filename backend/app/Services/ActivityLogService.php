<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\ActivitySection;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\User;
use App\Repositories\ActivityLogRepository;
use App\Repositories\UserRepository;
use App\Support\ShopCalendar;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ActivityLogService
{
    public function __construct(
        private readonly ActivityLogRepository $logs,
        private readonly UserRepository $users,
    ) {}

    /**
     * Write down what an admin has just done. Callers record inside the same database
     * transaction as the action itself, so an action that fails leaves no entry behind.
     *
     * @param  string  $description  One line, written for people: "Made bill INV-2026-0012 for …".
     * @param  list<array{field: string, old: string|null, new: string|null}>  $changes
     * @param  list<array{label: string, value: string|list<string>|null}>  $details  Lines without a value are left out.
     * @param  User|null  $actor  Who did it; by default the admin making the request.
     */
    public function record(
        ActivityAction $action,
        string $description,
        ?Model $subject = null,
        array $changes = [],
        array $details = [],
        ?User $actor = null,
    ): ActivityLog {
        // A failed login is never put down to anyone: whoever tried may not own the account.
        $actor ??= $action === ActivityAction::LoginFailed ? null : Auth::user();
        $details = array_values(array_filter($details, fn (array $detail) => ! in_array($detail['value'], [null, '', []], true)));
        [$type, $label] = $subject ? $this->subject($subject) : [null, null];

        return $this->logs->create([
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'action' => $action,
            'section' => $action->section(),
            'subject_type' => $type,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label === null ? null : Str::limit($label, 250),
            'description' => Str::limit($description, 990),
            'changes' => $changes ?: null,
            'details' => $details ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 250, '') ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters  Validated filters: user_id, section, from and to
     *                                         (dates by the shop's calendar), search, per_page.
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->logs->paginate([
            'user_id' => isset($filters['user_id']) ? (int) $filters['user_id'] : null,
            'section' => $filters['section'] ?? null,
            'from' => isset($filters['from']) ? ShopCalendar::start($filters['from']) : null,
            'until' => isset($filters['to']) ? ShopCalendar::after($filters['to']) : null,
            'search' => $filters['search'] ?? null,
        ], (int) ($filters['per_page'] ?? 50));
    }

    /**
     * What the log can be filtered by: the admins, and the parts of the shop.
     *
     * @return array{admins: list<array{id: int, name: string}>, sections: list<array{value: string, label: string}>}
     */
    public function filterOptions(): array
    {
        return [
            'admins' => $this->users->admins()->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all(),
            'sections' => array_map(
                fn (ActivitySection $section) => ['value' => $section->value, 'label' => $section->label()],
                ActivitySection::cases(),
            ),
        ];
    }

    /**
     * What kind of record it is, and its name as people know it.
     *
     * @return array{0: string, 1: string}
     */
    private function subject(Model $subject): array
    {
        return match (true) {
            $subject instanceof Bill => ['bill', $subject->bill_number],
            $subject instanceof Product => ['product', $subject->name],
            $subject instanceof Category => ['category', $subject->name],
            $subject instanceof Brand => ['brand', $subject->name],
            $subject instanceof StockEntry => ['stock_entry', $subject->supplier_name
                ? "Stock in from {$subject->supplier_name}"
                : 'Stock in of '.$subject->received_on->format('j M Y')],
            $subject instanceof User => [$subject->is_admin ? 'admin' : 'customer', $subject->name],
        };
    }
}
