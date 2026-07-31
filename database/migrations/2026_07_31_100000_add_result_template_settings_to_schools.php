<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school result-template settings.
 *
 * The primary school asked to drop the per-subject teacher comments from its
 * printed result and to have its Head of School SIGN rather than comment. The
 * secondary school wants neither change, and both print through the same
 * component — so the difference has to be data, not a branch on school id.
 *
 * These live on `schools` beside the result-template settings already there
 * (`name_on_result`, `result_approver_name`, `fallback_signature_id`) rather than
 * in a new settings table: same lifetime, same editor, same page.
 *
 * DEFAULTS ARE THE CURRENT BEHAVIOUR. Both flags default true and the title
 * defaults null, so every existing school — secondary included — prints exactly
 * what it printed before this migration. Only the primary school flips them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // Whether the per-subject "Comments" column (the subject teacher's own
            // remark) is PRINTED. Entry is unaffected: the score grids keep their
            // comment cells either way, so a school can toggle this without losing
            // anything already written.
            $table->boolean('show_subject_comments_on_result')
                ->default(true)
                ->after('result_approver_name');

            // Whether the Head of School's Name/Comment rows are printed. Primary
            // turns this off: there, the Head of School approves with a signature
            // and the written comment comes from the Key Stage Coordinator.
            $table->boolean('show_head_of_school_comment_on_result')
                ->default(true)
                ->after('show_subject_comments_on_result');

            // Title of whoever approves the result, e.g. "Head of School". Drives
            // the signature caption via ResultSignatureService::approvalLabel();
            // null keeps the existing "Reviewed and approved by {name}".
            $table->string('result_approver_title')
                ->nullable()
                ->after('show_head_of_school_comment_on_result');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn([
                'show_subject_comments_on_result',
                'show_head_of_school_comment_on_result',
                'result_approver_title',
            ]);
        });
    }
};
