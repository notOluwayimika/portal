<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `student_subjects.comment` from 50 to 100 characters.
 *
 * The column, the server rule (`StudentSubjectController@storeComment`) and the client check in
 * `score-entry-page.tsx` disagreed: 50 / 50 / 100. The shipped suggestion "This result is below
 * expectation. Put in more effort" is 52 characters, so a teacher who picked the FIRST entry of
 * the lowest band got a client-side pass and a server-side 422; four more defaults sat at exactly
 * 50, one character from the same fate.
 *
 * 100 is chosen because it is what the UI has always advertised — so this widens the column to
 * match the promise already on screen rather than shrinking the promise to match the column. All
 * three now agree on 100, and a test pins the bank entry limit to the same number so the three
 * cannot drift apart again.
 *
 * Purely widening: no existing value can fail to fit, so this is safe against live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_subjects', function (Blueprint $table) {
            $table->string('comment', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing back to 50 would TRUNCATE any comment written in the meantime, so trim first
        // and let the column shrink onto data that already fits. Lossy by nature — that is what
        // reverting a widening means — but it will not fail with MySQL 1406.
        DB::table('student_subjects')
            ->whereNotNull('comment')
            ->update(['comment' => DB::raw('LEFT(comment, 50)')]);

        Schema::table('student_subjects', function (Blueprint $table) {
            $table->string('comment', 50)->nullable()->change();
        });
    }
};
