<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Every trigger body must survive a dump/restore round trip.
 *
 * MySQL accepts an escaped apostrophe in a SIGNAL message at CREATE time and then
 * STORES THE BODY WITH THE ESCAPE STRIPPED. The trigger works in place, but
 * mysqldump, phpMyAdmin's copy and every other reader emit invalid SQL — a database
 * copy failed with "#1064 ... near 's terms are immutable" long after the migration
 * had run green.
 *
 * NEITHER ESCAPE SURVIVES. Verified directly: a body written with the SQL-standard
 * doubled quote (`'a policy''s terms'`) is stored as `'a policy's terms'` exactly as
 * the backslash form is. So an apostrophe cannot be carried in a trigger message at
 * all — the wording has to avoid one, which is what the fix did.
 *
 * The invariant this pins is deliberately mechanical rather than a search for
 * apostrophes: a well-formed body has BALANCED single quotes, so an odd count means
 * a literal is unterminated and the dump will not re-import. That catches the same
 * bug in any future trigger, whatever its wording.
 */
uses(RefreshDatabase::class);

it('leaves every trigger body with balanced quotes, so a dump can be restored', function () {
    $triggers = DB::select(
        'SELECT TRIGGER_NAME, ACTION_STATEMENT
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()'
    );

    expect($triggers)->not->toBeEmpty(
        'no triggers found — this test would pass vacuously and prove nothing'
    );

    $broken = [];

    foreach ($triggers as $trigger) {
        // COMMENTS ARE STRIPPED FIRST. Several of these bodies explain themselves in
        // `--` comments containing ordinary prose ("the invoice's currency"), and an
        // apostrophe there is harmless — the rest of the line is a comment. Counting
        // without stripping flagged three sound triggers on the first run.
        $sql = preg_replace('/--[^\n]*/', '', $trigger->ACTION_STATEMENT);
        $sql = preg_replace('#/\*.*?\*/#s', '', (string) $sql);

        if (substr_count((string) $sql, "'") % 2 !== 0) {
            $broken[] = $trigger->TRIGGER_NAME;
        }
    }

    expect($broken)->toBeEmpty(
        'trigger body has an unterminated string literal (usually an apostrophe in a '
        .'SIGNAL message, which MySQL stores unescaped): '.implode(', ', $broken)
    );
});
