<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair the quoting in `finance_discount_policies_update_guard`'s SIGNAL message.
 *
 * THE BUG. The original migration escaped the apostrophe in "a policy's terms" with
 * a BACKSLASH (`\'` after PHP's own unescaping). MySQL accepts that at CREATE time
 * under the default sql_mode, but it stores the body with the backslash STRIPPED:
 *
 *     stored: 'finance_discount_policies: a policy's terms are immutable; ...'
 *
 * The trigger therefore works in place, but every reader of it emits INVALID SQL —
 * mysqldump, phpMyAdmin's copy, any restore. Found when a database copy failed with
 * "#1064 ... near 's terms are immutable". The dump was faithful; the stored body
 * was already broken.
 *
 * It is fragile a second way: backslash escaping is disabled under
 * NO_BACKSLASH_ESCAPES, so the original CREATE would fail outright on a server
 * configured that way.
 *
 * THE FIX REMOVES THE APOSTROPHE, because NO ESCAPE SURVIVES. Verified against this
 * MySQL rather than assumed: a trigger created with the SQL-standard doubled quote
 *
 *     SET MESSAGE_TEXT = 'a policy''s terms'
 *
 * is ALSO stored as `'a policy's terms'`. MySQL normalises the escape away when it
 * records the body, so `''` is no safer than `\'` — both produce a dump that will
 * not re-import. An apostrophe simply cannot be carried in a trigger's MESSAGE_TEXT
 * here; the wording has to avoid one.
 *
 * NO DATA IS TOUCHED. A trigger is a schema object; DROP + CREATE rewrites the rule,
 * never the rows. `finance_discount_policies` is not read or written here.
 *
 * The guard's CONDITIONS are copied verbatim from the original so enforcement cannot
 * drift — only the message text differs. Note MySQL DDL is not transactional, so the
 * update guard is briefly absent between the two statements; run it with the app in
 * maintenance mode, as any migration touching a guard should be.
 */
return new class extends Migration
{
    private const UPDATE_GUARD = 'finance_discount_policies_update_guard';

    public function up(): void
    {
        $this->recreateGuard('policy terms are immutable; only status may change.');
    }

    public function down(): void
    {
        // Restores the ORIGINAL wording, and deliberately with the SAME broken
        // backslash escape — down() must reproduce the previous state, warts
        // included, or a rollback would leave a body neither migration wrote.
        $this->recreateGuard('a policy\\\'s terms are immutable; only status may change.');
    }

    private function recreateGuard(string $messageTail): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);

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
                        \'finance_discount_policies: '.$messageTail.'\';
                END IF;
             END'
        );
    }
};
