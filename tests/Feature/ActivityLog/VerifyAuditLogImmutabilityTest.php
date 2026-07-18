<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * The post-restore assertion (§15C, 1.4c): audit:verify-immutability must PASS
 * when the triggers are present and FAIL LOUDLY when a restore has stripped them.
 */
uses(RefreshDatabase::class);

it('passes when both immutability triggers are present', function () {
    $this->artisan('audit:verify-immutability')->assertExitCode(0);
});

it('fails loudly when a trigger has been stripped (the post-restore hazard)', function () {
    // Simulate a logical restore that dropped the triggers.
    DB::unprepared('DROP TRIGGER IF EXISTS activity_log_no_update');

    $this->artisan('audit:verify-immutability')->assertExitCode(1);

    // Restore it so the transaction rollback / next test starts clean.
    DB::unprepared(
        "CREATE TRIGGER activity_log_no_update BEFORE UPDATE ON activity_log
         FOR EACH ROW SIGNAL SQLSTATE '45000'
         SET MESSAGE_TEXT = 'activity_log is append-only and immutable (Constitution §15C): UPDATE is denied.';"
    );
});
