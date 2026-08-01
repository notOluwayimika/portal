<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fourth result-template setting: whether the behaviour comment is PRINTED.
 *
 * The primary school asked for the behavioural-assessment comment to come off its
 * result. Secondary wants it, and both print through `AttributionRows` in
 * curriculum-card-final.tsx — so, as with the three settings added in
 * 2026_07_31_100000, the difference is data rather than a branch on school id.
 *
 * ONE FLAG COVERS BOTH LABELS. The row is `behavioral_assessments[0].comment`
 * printed under one of two captions — "Boarding Parent Comment" where the school
 * runs boarding and one is assigned for this student, "Behaviour Comment" everywhere
 * else. They are the same field and the same request, so a second flag would only
 * create a state nobody asked for.
 *
 * ENTRY IS UNAFFECTED, exactly like `show_subject_comments_on_result`: boarding
 * parents and form tutors keep writing the comment and nothing already stored is
 * touched. The flag decides printing only, so it is reversible with no data loss.
 *
 * DEFAULT IS THE CURRENT BEHAVIOUR — true, so every existing school including
 * secondary prints what it printed before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('show_behaviour_comment_on_result')
                ->default(true)
                ->after('result_approver_title');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('show_behaviour_comment_on_result');
        });
    }
};
