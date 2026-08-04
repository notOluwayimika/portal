<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.email` becomes NULLABLE — for accounts that cannot log in.
 *
 * ⚠️ HARD ORDERING CONSTRAINT, RECORDED HERE SO A REVERT CANNOT SILENTLY REORDER IT.
 * `User::routeNotificationForMail()` MUST already be overridden when this runs.
 * Laravel's default routes mail to `$this->email`; the moment that column can be
 * null, an unoverridden router turns every `notify()` — and every
 * `Password::sendResetLink → ResetPassword` — into a send-to-null BY ACCIDENT. The
 * override is what converts "email is nullable" from a latent null-route into a
 * defined skip. Reverting the override while leaving this migration applied
 * reinstates the hazard with no test failing, which is why the dependency is written
 * in the schema rather than only in a PR description.
 *
 * NULLABLE, NOT UNCONSTRAINED. #203's invariant is the real constraint: `can_login ⟹
 * deliverable email`, enforced at every transition that can raise `can_login`. It
 * cannot be a CHECK here, because `can_login` lives on the `guardian_student` PIVOT —
 * "is a login account" is an AGGREGATE over pivots, not a column on this table.
 *
 * AND IT MATTERS MORE THAN IT LOOKS: login is email-only
 * (`User::where('email', $request->email)`), and `Password::sendResetLink` resolves
 * the user BY this column before the address is ever a delivery target. A login
 * account with a null email is the one row where the broker finds NOBODY — the
 * override cannot help, because it is never reached. That is why this PR is
 * release-gated on `guardians:audit-login-invariant` returning ZERO in production.
 *
 * WHY NULLABLE AT ALL. The synthetic `{phone}@no-email.local` mint existed solely to
 * satisfy NOT NULL + UNIQUE for guardians on record with no address. Retiring the
 * mint without this column change would either block creating such a guardian or
 * reintroduce a placeholder under another name — the two are one change.
 *
 * UNIQUE SURVIVES: MySQL permits repeated NULLs in a unique index, so every
 * address-less account coexists while real addresses stay unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reversal cannot recreate the placeholders it never wrote.
     *
     * Restoring NOT NULL would fail against any row this release legitimately created
     * with a null address, so the rollback deliberately stops short: it refuses rather
     * than inventing a value. A down() that minted fresh synthetic addresses to
     * satisfy the constraint would resurrect exactly the sentinel this release exists
     * to retire — and would do it silently, at rollback time, when nobody is looking.
     */
    public function down(): void
    {
        $nullEmails = DB::table('users')->whereNull('email')->count();

        if ($nullEmails > 0) {
            throw new RuntimeException(
                "Cannot restore users.email NOT NULL: {$nullEmails} account(s) legitimately "
                .'have no address. Reversing this migration requires deciding what those '
                .'accounts should hold — which is a data decision, not a schema one. '
                .'Minting placeholders here would resurrect the sentinel this release retired.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
