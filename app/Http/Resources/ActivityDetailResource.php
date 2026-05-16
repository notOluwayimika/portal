<?php

namespace App\Http\Resources;

use App\Services\ActivityLog\ActivitySensitiveService;
use App\Services\ActivityLog\ActivitySeverityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full single-activity payload for the detail drawer / permalink.
 * Includes the masked properties JSON and a computed before/after diff.
 */
class ActivityDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sensitive = ActivitySensitiveService::make();
        $base = (new ActivityResource($this->resource))->toArray($request);

        $props = $sensitive->maskProperties($this->properties ?? []) ?? [];

        return array_merge($base, [
            'properties' => $props,
            'diff'       => $this->buildDiff($props, $sensitive),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Only fields present in BOTH `old` and `attributes` whose values differ.
     * Skips `*_id` fields that have a human-readable counterpart. Sensitive
     * field names are masked on both sides.
     */
    protected function buildDiff(array $props, ActivitySensitiveService $sensitive): array
    {
        $old = $props['old'] ?? null;
        $new = $props['attributes'] ?? null;

        if (! is_array($old) || ! is_array($new)) {
            return [];
        }

        $diff = [];
        foreach ($new as $field => $newValue) {
            if (! array_key_exists($field, $old)) {
                continue;
            }

            $oldValue = $old[$field];
            if ($oldValue === $newValue) {
                continue;
            }

            // Skip *_id fields that have a human-readable counterpart present.
            if (str_ends_with($field, '_id')) {
                $human = substr($field, 0, -3);
                if (array_key_exists($human, $new)) {
                    continue;
                }
            }

            $masked = $sensitive->isMaskedField($field);

            $diff[] = [
                'field'  => $field,
                'old'    => $masked ? '***' : $oldValue,
                'new'    => $masked ? '***' : $newValue,
                'masked' => $masked,
            ];
        }

        return $diff;
    }
}
