<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C7 — a role can be declared to require two-factor enrolment. Backfills TRUE
 * for super_admin and admin only: the plan's 4 Finance roles are NOT seeded
 * (step-0 re-derivation) — their default is the Finance owner's I6 call and is
 * held for that conversation, not invented here. Fresh installs get the same
 * defaults from RbacSeeder; this backfill covers existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('two_factor_required')->default(false)->after('guard_name');
        });

        // Both guard rows for super_admin (web + api) — the requirement is
        // account-global (c7 D1), so the flag should not differ per guard.
        DB::table('roles')
            ->whereIn('name', ['super_admin', 'admin'])
            ->update(['two_factor_required' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('two_factor_required');
        });
    }
};
