<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('guardians')
            ->whereNotNull('user_id')
            ->select(['school_id', 'user_id'])
            ->distinct()
            ->orderBy('user_id')
            ->chunk(500, function ($guardians) {
                $now = now();
                $rows = $guardians->map(fn ($guardian) => [
                    'school_id' => $guardian->school_id,
                    'user_id' => $guardian->user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('school_user')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        // Existing school access may predate this migration and must not be
        // removed on rollback.
    }
};
