<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('sport_house_id')->nullable()->after('photo_id')->constrained('sport_houses')->nullOnDelete();
            $table->foreignId('scholarship_id')->nullable()->after('sport_house_id')->constrained('scholarships')->nullOnDelete();
            $table->date('admission_date')->nullable()->after('scholarship_id');
            $table->text('address')->nullable()->after('admission_date');
            $table->string('nationality')->nullable()->after('address');
            $table->string('other_nationality')->nullable()->after('nationality');
            $table->string('state_of_origin')->nullable()->after('other_nationality');
            $table->string('religion')->nullable()->after('state_of_origin');
            $table->string('previous_school')->nullable()->after('religion');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sport_house_id');
            $table->dropConstrainedForeignId('scholarship_id');
            $table->dropColumn([
                'admission_date',
                'address',
                'nationality',
                'other_nationality',
                'state_of_origin',
                'religion',
                'previous_school',
            ]);
        });
    }
};
