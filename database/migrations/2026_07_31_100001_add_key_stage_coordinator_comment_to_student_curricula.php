<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Key Stage Coordinator's comment on an enrollment.
 *
 * A THIRD comment column rather than a reuse of `head_of_school_comment`, because
 * the two are different people with different assignments and can both exist on the
 * same enrollment. Sharing one column would make "who wrote this" depend on which
 * assignment happened to exist at read time — the same class of ambiguity that made
 * a boarding parent's label print over a form tutor's remark (ADR-less, see
 * curriculum-card-final's AttributionRows).
 *
 * Nullable and unindexed, matching `form_teacher_comment` and
 * `head_of_school_comment`: it is written once per enrollment by one person and only
 * ever read through that enrollment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_curricula', function (Blueprint $table) {
            $table->text('key_stage_coordinator_comment')
                ->nullable()
                ->after('head_of_school_comment');
        });
    }

    public function down(): void
    {
        Schema::table('student_curricula', function (Blueprint $table) {
            $table->dropColumn('key_stage_coordinator_comment');
        });
    }
};
