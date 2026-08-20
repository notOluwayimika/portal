<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promotion config, part 1 of 3 — the parent unique keys the config's composite FKs reference.
 *
 * WHY COMPOSITE, AND WHY THIS FILE EXISTS AT ALL. The progression config decides, for a given class
 * level, WHICH class level a pupil is promoted into, WHICH arm they land in, and WHICH exam type the
 * new curriculum carries. A single-column FK on any of those would be satisfied by a row belonging to
 * ANOTHER SCHOOL — referentially valid, semantically catastrophic: the end-of-year job would promote a
 * pupil out of their school entirely. That is the identical argument 2026_07_19_130000 made when it
 * converted student_id/curriculum_id to composite `(x, school_id)` FKs, and 2026_07_29_130000 made
 * again for promoted_to_id. `school_id` is the only isolation boundary (Constitution 1/13), so a
 * config table that steers enrollment gets the same treatment rather than trusting SchoolScope — a
 * global scope is an application fact, and this is a database one.
 *
 * A composite child FK needs a UNIQUE on the parent's `(id, school_id)`. None of these three tables
 * has one, so they are added here, BEFORE the columns and tables that reference them. `id` alone is
 * already unique, so each of these is strictly additive and cannot fail on existing data — the same
 * risk-free trick `students_id_school_unique` used in the July-19 migration.
 *
 * SPLIT INTO ITS OWN MIGRATION deliberately. These three ALTERs are prerequisites shared by BOTH
 * following migrations (the class_levels columns and the three config tables). Keeping them separate
 * means each of the three has an exact, independently reversible down(), instead of one migration whose
 * rollback has to unpick FKs and indexes it added at different times.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, index: string}>
     */
    private const KEYS = [
        ['table' => 'class_levels', 'index' => 'class_levels_id_school_unique'],
        ['table' => 'class_level_arms', 'index' => 'class_level_arms_id_school_unique'],
        ['table' => 'exam_types', 'index' => 'exam_types_id_school_unique'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $key) {
            DB::statement(
                "ALTER TABLE {$key['table']} ADD UNIQUE {$key['index']} (id, school_id)"
            );
        }
    }

    /**
     * Reverse order, so a rollback undoes exactly what up() added. Safe unconditionally: the two
     * migrations that consume these keys are timestamped AFTER this one, so `migrate:rollback` has
     * already dropped their FKs by the time this runs. Rolling this back while one of those FKs still
     * existed would fail loudly on the dependent constraint rather than corrupt anything.
     */
    public function down(): void
    {
        foreach (array_reverse(self::KEYS) as $key) {
            DB::statement("ALTER TABLE {$key['table']} DROP INDEX {$key['index']}");
        }
    }
};
