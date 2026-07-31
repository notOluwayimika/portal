<?php

namespace App\Concerns;

use App\Models\ClassLevelArm;

trait FormatsClassLevelArmName
{
    protected function classLevelArmName(ClassLevelArm $classLevelArm): string
    {
        $name = $classLevelArm->classLevel->name.' '.$classLevelArm->arm->label;

        if ($classLevelArm->stream) {
            $name .= ' ('.$classLevelArm->stream->name.')';
        }

        return $name;
    }

    /**
     * Name PLUS the identifiers a client needs to filter by class level and arm.
     *
     * The comment and assessment screens all rendered `class_name` — a formatted
     * string — and nothing else, so filtering by level or arm was impossible without
     * parsing that label. Returned as one array from the trait every one of those
     * controllers already uses, so the four row shapes cannot drift apart.
     *
     * uuids, not ids: these cross the wire, and every other identifier in these
     * payloads is a uuid.
     *
     * @return array{class_name: string|null, class_level: array{id: string, name: string}|null, class_level_arm: array{id: string, name: string}|null}
     */
    protected function classLevelArmIdentity(?ClassLevelArm $classLevelArm): array
    {
        if (! $classLevelArm) {
            return ['class_name' => null, 'class_level' => null, 'class_level_arm' => null];
        }

        $classLevel = $classLevelArm->classLevel;
        $name = $this->classLevelArmName($classLevelArm);

        return [
            'class_name' => $name,
            'class_level' => $classLevel
                ? ['id' => $classLevel->uuid, 'name' => $classLevel->name]
                : null,
            'class_level_arm' => ['id' => $classLevelArm->uuid, 'name' => $name],
        ];
    }
}
