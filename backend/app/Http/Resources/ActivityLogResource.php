<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => [
                'value' => $this->action->value,
                'label' => $this->action->label(),
            ],
            'section' => [
                'value' => $this->section->value,
                'label' => $this->section->label(),
            ],
            'description' => $this->description,
            // What it was about. "exists" is false once the action deleted it, so there is
            // nothing to open.
            'subject' => $this->subject_type ? [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $this->subject_label,
                'exists' => ! $this->action->removesSubject(),
            ] : null,
            'user' => $this->user_name ? ['id' => $this->user_id, 'name' => $this->user_name] : null,
            'changes' => $this->changes ?? [],
            'details' => $this->details ?? [],
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at,
        ];
    }
}
