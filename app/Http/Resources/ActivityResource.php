<?php

namespace App\Http\Resources;

use App\Services\ActivityLog\ActivitySeverityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Lightweight list item. The heavy `properties` JSON is intentionally NOT
 * included here (only a `has_diff` flag) — it is lazy-loaded by the detail
 * endpoint / drawer. See implementation_plan.md §9.
 */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $severity = ActivitySeverityService::make()->for($this->log_name, $this->event);
        $props = $this->properties ?? [];
        $hasDiff = isset($props['attributes']) && isset($props['old']);

        $canSeeSchool = $request->user()?->can('activity_log.view_system')
            || $request->user()?->can('activity_log.view_cross_school');

        return [
            'id'          => $this->id,
            'log_name'    => $this->log_name,
            'event'       => $this->event,
            'severity'    => $severity,
            'description' => $this->description,
            'causer'      => $this->causerPayload(),
            'subject'     => $this->subjectPayload(),
            'batch_uuid'  => $this->batch_uuid,
            'has_diff'    => $hasDiff,
            'is_system'   => $this->school_id === null,
            'school_id'   => $this->when($canSeeSchool, $this->school_id),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }

    protected function causerPayload(): array
    {
        if (! $this->causer) {
            return [
                'id'      => $this->causer_id,
                'name'    => $this->causer_id ? "Deleted user (#{$this->causer_id})" : 'System',
                'role'    => null,
                'avatar'  => null,
                'deleted' => (bool) $this->causer_id,
            ];
        }

        $causer = $this->causer;

        return [
            'id'      => $causer->getKey(),
            'name'    => $causer->full_name ?? $causer->name ?? "User #{$causer->getKey()}",
            'role'    => method_exists($causer, 'getRoleNames')
                ? $causer->getRoleNames()->first()
                : null,
            'avatar'  => $causer->avatar ?? null,
            'deleted' => false,
        ];
    }

    protected function subjectPayload(): ?array
    {
        if (! $this->subject_type) {
            return null;
        }

        $basename = class_basename($this->subject_type);

        if (! $this->subject) {
            // Fall back to a name captured in properties when the record is gone.
            $props = $this->properties ?? [];
            $fallback = $props['attributes']['name']
                ?? trim(($props['attributes']['first_name'] ?? '') . ' ' . ($props['attributes']['last_name'] ?? ''))
                ?: null;

            return [
                'type'    => $basename,
                'id'      => $this->subject_id,
                'label'   => $fallback ? trim($fallback) : "{$basename} #{$this->subject_id}",
                'exists'  => false,
            ];
        }

        $subject = $this->subject;
        $label = $subject->full_name
            ?? $subject->name
            ?? trim(($subject->first_name ?? '') . ' ' . ($subject->last_name ?? ''))
            ?: "{$basename} #{$subject->getKey()}";

        return [
            'type'   => $basename,
            'id'     => $subject->getKey(),
            'uuid'   => $subject->uuid ?? null,
            'label'  => Str::of($label)->trim()->value(),
            'exists' => true,
        ];
    }
}
