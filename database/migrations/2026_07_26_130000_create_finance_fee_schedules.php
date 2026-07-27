<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S1 commit 2 — the pricing catalog (finance_fee_schedules). A per-School catalog of prices keyed to
 * (term × class level), consulted at billing time and NEVER displayed on a statement. It is a live
 * catalog, not a historical document, which is why it carries live RESTRICT FKs to `terms` and
 * `class_levels` — the snapshot rule (fee_invoices rule 3) governs invoice DOCUMENTS, whose lines copy
 * description + amount and join nothing. Once a term has been priced, it cannot be deleted.
 *
 * LIFECYCLE: draft → active → superseded | retired. `status` and `supersedes_schedule_id` are here in
 * commit 2 (not added by commit 4) because Ruling 2 puts fee-schedule approval in this slice, so they
 * have a consumer within it — not front-loading.
 *
 * UNIQUENESS is per-lifecycle-state, via the generated-column idiom `finance_void_requests` establishes
 * — NOT a plain composite unique. A draft and an active schedule for the same (term, class level) must
 * coexist (that is the point of a draft), and a superseded schedule keeps its term/class level forever
 * (an invoice raised against it must stay explicable). So `unique(school_id, term_id, class_level_id)`
 * is wrong both ways. Instead: one active and one draft, each singular per (school, term, class level),
 * by a STORED generated key that is NULL off that state — and MySQL exempts a row from a unique index
 * when any indexed column is NULL, so superseded/retired rows never collide.
 *
 * The `(id, school_id)` unique key is the parent key that finance_fee_items' composite school-integrity
 * FK references (2026_07_19_110001 pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_fee_schedules', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
            $table->foreignId('class_level_id')->constrained('class_levels')->restrictOnDelete();
            $table->string('label');                                          // school-authored
            $table->string('status')->default('draft');                       // draft|active|superseded|retired
            $table->unsignedBigInteger('supersedes_schedule_id')->nullable(); // LOOKUP, set on republish
            $table->timestamps();
        });

        // Parent key for finance_fee_items' composite (fee_schedule_id, school_id) FK.
        DB::statement('ALTER TABLE finance_fee_schedules ADD UNIQUE finance_fee_schedules_id_school_unique (id, school_id)');

        // Per-lifecycle-state singularity: at most one ACTIVE and one DRAFT per (school, term, class level).
        DB::statement(
            "ALTER TABLE finance_fee_schedules
                ADD COLUMN active_term_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'active', term_id, NULL)) STORED,
                ADD COLUMN active_class_level_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'active', class_level_id, NULL)) STORED,
                ADD COLUMN draft_term_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'draft', term_id, NULL)) STORED,
                ADD COLUMN draft_class_level_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'draft', class_level_id, NULL)) STORED"
        );
        DB::statement(
            'ALTER TABLE finance_fee_schedules
                ADD UNIQUE finance_fee_schedules_active_unique (school_id, active_term_key, active_class_level_key),
                ADD UNIQUE finance_fee_schedules_draft_unique  (school_id, draft_term_key,  draft_class_level_key)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_fee_schedules');
    }
};
