<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFileUploadsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('file_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 255);
            $table->string('folder_path');
            $table->string('url');
            $table->timestamps();

            $table->index('uuid');
            $table->index('name');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('photo');
            $table->foreignId('photo_id')->nullable()->constrained('file_uploads')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['photo_id']);
            $table->dropColumn('photo_id');
            $table->string('photo')->nullable();
        });

        Schema::dropIfExists('file_uploads');
    }
}
