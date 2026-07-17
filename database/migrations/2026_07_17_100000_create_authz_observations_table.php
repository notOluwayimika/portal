<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observe-mode evidence store for the S5 authorization rollout. Each row is one
 * would-be denial recorded by App\Support\Authz::observe() while enforcement is
 * OFF, so the operational impact of restoring each dormant check can be measured
 * on real traffic before any request is actually blocked.
 *
 * Queried via `php artisan authz:observations` (aggregation) or raw SQL. Not a
 * log file — 1.4d observability has not landed, so evidence lives in the app DB.
 * This is platform infrastructure, not a School-owned table (no BelongsToSchool);
 * school_id is captured as data, and rows are pruned when the rollout completes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authz_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();   // no FK: keep the row if the user is later deleted
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('ability');                  // e.g. guardian.update, or a named ownership/role check
            $table->string('check_type')->default('permission'); // permission | ownership | role | business_rule
            $table->string('controller_action');        // Controller@method
            $table->string('route')->nullable();        // route name
            $table->string('request_uri', 1024)->nullable();
            $table->string('method', 10)->nullable();   // GET/POST/…
            $table->string('transport', 16);            // http | api | console | queue
            $table->json('roles')->nullable();          // role names in the active School context
            $table->timestamp('occurred_at')->useCurrent();

            $table->index('ability');
            $table->index('controller_action');
            $table->index('route');
            $table->index('school_id');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authz_observations');
    }
};
