<?php

namespace App\Models;

use App\Enums\ActivityAction;
use App\Enums\ActivitySection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One thing an admin did. Entries are only ever added: the log is trusted because nobody,
 * from the app or the code, can change or remove what it says.
 */
#[Fillable([
    'user_id', 'user_name', 'action', 'section', 'subject_type', 'subject_id', 'subject_label',
    'description', 'changes', 'details', 'ip_address', 'user_agent',
])]
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Activity log entries cannot be deleted.'));
    }

    /**
     * The admin who did it, while their account exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'section' => ActivitySection::class,
            'changes' => 'array',
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
