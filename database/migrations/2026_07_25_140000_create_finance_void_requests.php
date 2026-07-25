<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ph3b — maker-checker on invoice VOID (the second instance of the Ph3 template).
 *
 * Voiding an invoice removes a charge (the same balance outcome as a 100% credit note),
 * yet today the cancel route is gated only by finance.access — any finance user erases an
 * obligation on one signature. This slice makes voiding a REQUEST that a second person
 * (≠ the maker) must approve; only on approval does the invoice flip to void and its
 * reversing ledger entry post.
 *
 * A DEDICATED REQUEST DOCUMENT — not lifecycle columns on the invoice. That is the whole
 * point: F7's active_enrollment_key = IF(status='issued', student_curriculum_id, NULL)
 * frees the "one active invoice per episode" slot the moment the invoice leaves 'issued'.
 * If a pending void moved the invoice off 'issued', it would free the slot before anyone
 * approved. So the void lifecycle lives HERE, and invoices.status moves only at approval —
 * the pending-void trap is defused by construction, not by discipline.
 *
 * Structure mirrors the credit-note maker-checker exactly (submitted_by / decided_by /
 * decided_at / rejection_reason + the maker≠checker CHECK + the money-immutable-but-
 * status-mutable trigger). The SEMANTICS differ (void reverses the whole charge; there is
 * no ceiling, no currency) so this table carries neither.
 *
 * ONE OPEN REQUEST PER INVOICE — MySQL has no partial unique index, so the F7 idiom: a
 * STORED generated column `open_key = IF(status='submitted', invoice_id, NULL)` + UNIQUE.
 * Two makers cannot submit two pending voids for one invoice (two approvals would
 * double-reverse); a decided request (open_key NULL) does not collide, so a fresh submit
 * after a rejection still works.
 *
 * NO INSERT GUARD (deliberate, unlike credit notes). The credit-note insert guard existed
 * to enforce the ceiling + currency for a raw approved insert; void requests have neither.
 * A raw INSERT already carrying status='approved' forges an audit row but moves NO money
 * and voids NOTHING — the reversal and the invoice flip are done ONLY by ApproveVoidRequest,
 * never by an insert. The invoice stays 'issued' and in the balance; ReconcileAccounts would
 * surface an approved request whose reversal never posted. So there is nothing to enforce at
 * insert beyond the UNIQUE (open slot) and the CHECK (maker≠checker), both constraints.
 *
 * down() (#85, by name): drops the two triggers, the CHECK, and the table (which takes the
 * generated column + UNIQUE with it). It restores NO one-step void permission — there never
 * was one (void was gated only by finance.access); retiring the cancel route/controller/
 * request/action is a code change, reversed outside this migration. No invoice table change
 * to reverse — the invoice is deliberately untouched.
 */
return new class extends Migration
{
    private const CHECK = 'finance_void_requests_maker_ne_checker';

    private const NO_DELETE = 'finance_void_requests_no_delete';

    private const UPDATE_GUARD = 'finance_void_requests_update_guard';

    public function up(): void
    {
        Schema::create('finance_void_requests', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            // Composite (invoice_id, school_id) FK (F3): a void request's School can never
            // diverge from its invoice's — references finance_invoices(id, school_id).
            $table->unsignedBigInteger('invoice_id');

            $table->string('reason'); // the maker's why — required at submit

            $table->string('status')->default('submitted'); // VoidRequestStatus
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();

            $table->foreign(['invoice_id', 'school_id'], 'finance_void_requests_invoice_school_foreign')
                ->references(['id', 'school_id'])->on('finance_invoices')->restrictOnDelete();
        });

        // One OPEN (submitted) request per invoice — the F7 idiom. GENERATED, so no code path
        // can forget to set or clear it; the invariant holds by construction.
        DB::statement(
            "ALTER TABLE finance_void_requests
                ADD COLUMN open_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'submitted', invoice_id, NULL)) STORED"
        );
        DB::statement(
            'ALTER TABLE finance_void_requests
                ADD UNIQUE finance_void_requests_open_unique (school_id, open_key)'
        );

        // Structural maker ≠ checker — the real control (holds for raw writes, jobs, tinker);
        // the Policy is the friendly layer. NULL guards: a draft has no decider, a backfilled
        // row no recoverable maker.
        DB::statement(
            'ALTER TABLE finance_void_requests
                ADD CONSTRAINT '.self::CHECK.'
                CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by)'
        );

        // Un-deletable (a request is retained for audit, even rejected).
        DB::unprepared(
            'CREATE TRIGGER '.self::NO_DELETE.' BEFORE DELETE ON finance_void_requests
             FOR EACH ROW SIGNAL SQLSTATE \'45000\'
             SET MESSAGE_TEXT = \'finance_void_requests is retained for audit: DELETE is denied.\';'
        );

        // Money/identity immutable; only the decision columns may move (mirror the credit-note
        // relaxed trigger). NEW.x vs OLD.x are the same column → plain <>/<=> (no #95 variable
        // collation issue arises here; there is no DECLAREd variable).
        DB::unprepared(
            'CREATE TRIGGER '.self::UPDATE_GUARD.' BEFORE UPDATE ON finance_void_requests
             FOR EACH ROW
             BEGIN
                IF NEW.invoice_id <> OLD.invoice_id
                    OR NEW.school_id <> OLD.school_id
                    OR NOT (NEW.reason <=> OLD.reason)
                    OR NOT (NEW.submitted_by <=> OLD.submitted_by)
                    OR NEW.uuid <> OLD.uuid THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_void_requests: only status/decided_by/decided_at/rejection_reason may change.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::NO_DELETE);
        // The CHECK and the generated column + UNIQUE go with the table.
        Schema::dropIfExists('finance_void_requests');
    }
};
