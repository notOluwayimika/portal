<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $schoolIds = DB::table('schools')->pluck('id');

        $defaults = [
            ['name' => 'General', 'slug' => 'general', 'color' => 'gray'],
            ['name' => 'Finance', 'slug' => 'finance', 'color' => 'red'],
            ['name' => 'Event', 'slug' => 'event', 'color' => 'amber'],
            ['name' => 'Achievement', 'slug' => 'achievement', 'color' => 'green'],
        ];

        foreach ($schoolIds as $schoolId) {
            foreach ($defaults as $cat) {
                DB::table('notice_categories')->insert([
                    'uuid' => (string) Str::orderedUuid(),
                    'school_id' => $schoolId,
                    'name' => $cat['name'],
                    'slug' => $cat['slug'],
                    'color' => $cat['color'],
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('notice_categories')->where('is_default', true)->delete();
    }
};
