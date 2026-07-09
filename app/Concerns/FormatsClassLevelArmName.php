<?php

namespace App\Concerns;

use App\Models\ClassLevelArm;

trait FormatsClassLevelArmName
{
    protected function classLevelArmName(ClassLevelArm $classLevelArm): string
    {
        $name = $classLevelArm->classLevel->name . ' ' . $classLevelArm->arm->label;

        if ($classLevelArm->stream) {
            $name .= ' (' . $classLevelArm->stream->name . ')';
        }

        return $name;
    }
}
