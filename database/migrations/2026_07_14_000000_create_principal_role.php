<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Role::firstOrCreate(['name' => 'principal', 'guard_name' => 'web']);
    }

    public function down(): void
    {
        Role::where('name', 'principal')->where('guard_name', 'web')->delete();
    }
};
