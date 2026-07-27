<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S1 commit 3 — the discount-policy catalog (axis A). Only ever WRITTEN by an approved change request
 * (ApproveDiscountPolicyChange); it holds no approval columns of its own. Money/identity never mutate —
 * only `status` moves (active → superseded | retired), exactly the credit-note / void-request treatment.
 *
 * BASIS CHECK makes "naira OR percentage, never both, never neither" a database fact — both were asked
 * for ("make both available"), and a nullable pair with no constraint is how a policy means nothing.
 *
 * NAME uniqueness is state-scoped (the void-request generated-column idiom): a superseded/retired policy
 * keeps its name forever (provenance must stay readable), so `unique(school_id, name)` would make amend
 * impossible — the new row would collide with the one it supersedes. `active_name_key` is the name only
 * while active; MySQL exempts NULL, so only one ACTIVE policy per name per school.
 *
 * The `(id, school_id)` unique is the parent key finance_discount_policy_changes' composite FK references.
 */
return new class extends Migration
{
    private const UPDATE_GUARD = 'finance_discount_policies_update_guard';

    private const NO_DELETE = 'finance_discount_policies_no_delete';

    public function up(): void
    {
        Schema::create('finance_discount_policies', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();

            $table->string('name');                       // school-authored, free text
            $table->string('description', 500)->nullable();

            $table->string('basis', 16);                  // 'amount' | 'percent'
            $table->bigInteger('value_minor')->nullable();      // iff basis = amount
            $table->char('value_currency', 3)->nullable();      // iff basis = amount
            $table->unsignedTinyInteger('percent')->nullable(); // iff basis = percent, 1..100

            $table->boolean('requires_approval')->default(false); // AXIS B

            $table->string('status')->default('active');  // active | superseded | retired
            $table->unsignedBigInteger('supersedes_policy_id')->nullable(); // LOOKUP, set on amendment

            $table->timestamps();
        });

        DB::statement('ALTER TABLE finance_discount_policies ADD UNIQUE finance_discount_policies_id_school_unique (id, school_id)');

        DB::statement(
            "ALTER TABLE finance_discount_policies
                ADD CONSTRAINT finance_discount_policies_basis_exclusive CHECK (
                    (basis = 'amount'  AND value_minor IS NOT NULL AND value_currency IS NOT NULL AND percent IS NULL)
                    OR
                    (basis = 'percent' AND percent IS NOT NULL AND percent BETWEEN 1 AND 100
                                       AND value_minor IS NULL AND value_currency IS NULL)
                )"
        );

        DB::statement(
            "ALTER TABLE finance_discount_policies
                ADD COLUMN active_name_key VARCHAR(255)
                    GENERATED ALWAYS AS (IF(status = 'active', name, NULL)) STORED"
        );
        DB::statement('ALTER TABLE finance_discount_policies ADD UNIQUE finance_discount_policies_active_name_unique (school_id, active_name_key)');

        // Un-deletable (a policy is provenance for every reduction that ever cited it).
        DB::unprepared(
            'CREATE TRIGGER '.self::NO_DELETE.' BEFORE DELETE ON finance_discount_policies
             FOR EACH ROW SIGNAL SQLSTATE \'45000\'
             SET MESSAGE_TEXT = \'finance_discount_policies is retained for audit: DELETE is denied.\';'
        );

        // Terms immutable; only `status` (and description/timestamps) may move. NEW.x vs OLD.x are the
        // same column → plain <> / <=> (no #95 DECLAREd-variable collation issue).
        DB::unprepared(
            'CREATE TRIGGER '.self::UPDATE_GUARD.' BEFORE UPDATE ON finance_discount_policies
             FOR EACH ROW
             BEGIN
                IF NEW.name <> OLD.name
                    OR NEW.basis <> OLD.basis
                    OR NOT (NEW.value_minor <=> OLD.value_minor)
                    OR NOT (NEW.value_currency <=> OLD.value_currency)
                    OR NOT (NEW.percent <=> OLD.percent)
                    OR NEW.requires_approval <> OLD.requires_approval
                    OR NEW.school_id <> OLD.school_id
                    OR NEW.uuid <> OLD.uuid
                    OR NOT (NEW.supersedes_policy_id <=> OLD.supersedes_policy_id) THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_discount_policies: a policy\\\'s terms are immutable; only status may change.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::NO_DELETE);
        Schema::dropIfExists('finance_discount_policies');
    }
};
