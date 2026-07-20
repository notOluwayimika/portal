<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update terms table
        Schema::table('terms', function (Blueprint $table) {
            if (!Schema::hasColumn('terms', 'slug')) {
                $table->string('slug')->after('name');
                $table->unique(['academic_session_id', 'slug']);
            }
        });

        // 2. Seed terms (we need them for data migration)
        // Note: Running seeder here to ensure terms exist before migrating curricula data
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'TermSeeder', '--force' => true]);

        // 3. Update curricula table
        Schema::table('curricula', function (Blueprint $table) {
            if (!Schema::hasColumn('curricula', 'term_id')) {
                $table->foreignId('term_id')->nullable()->after('exam_type_id')->constrained()->cascadeOnDelete();
            }
        });

        // 4. Migrate data
        $curricula = DB::table('curricula')->get();
        foreach ($curricula as $curriculum) {
            $term = DB::table('terms')
                ->where('academic_session_id', $curriculum->academic_session_id)
                ->where('order', $curriculum->term)
                ->first();

            if ($term) {
                DB::table('curricula')
                    ->where('id', $curriculum->id)
                    ->update(['term_id' => $term->id]);
            }
        }

        // 5. Cleanup curricula table
        if (Schema::hasColumn('curricula', 'academic_session_id')) {
            // Try to drop the unique key if it exists
            try {
                Schema::table('curricula', function (Blueprint $table) {
                    $table->dropUnique('curricula_unique_key');
                });
            } catch (\Exception $e) {
                // Ignore if unique key already dropped
            }

            // Drop the foreign key in its own call: blueprint commands only
            // compile when Schema::table runs, so a try/catch must wrap the
            // whole call. SQLite supports dropping foreign keys by column
            // (via table recreation) but not by name — and it refuses to
            // drop a column still referenced by a foreign key definition.
            try {
                Schema::table('curricula', function (Blueprint $table) {
                    $table->dropForeign('fk_curricula_academic_session_id');
                });
            } catch (\Exception $e) {
                try {
                    Schema::table('curricula', function (Blueprint $table) {
                        $table->dropForeign(['academic_session_id']);
                    });
                } catch (\Exception $e2) {
                    // Already dropped or different name
                }
            }

            // The composite index also references the column and blocks the
            // drop on sqlite; replace it with the surviving columns.
            try {
                Schema::table('curricula', function (Blueprint $table) {
                    $table->dropIndex('curricula_school_id_academic_session_id_status_index');
                    $table->index(['school_id', 'status']);
                });
            } catch (\Exception $e) {
                // Index already dropped or renamed
            }

            Schema::table('curricula', function (Blueprint $table) {
                $table->dropColumn(['term', 'academic_session_id']);

                // Re-add unique constraint with term_id instead of term and academic_session_id
                $table->unique(['school_id', 'class_level_arm_id', 'term_id', 'exam_type_id', 'is_ccm'], 'curricula_unique_key');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('curricula', function (Blueprint $table) {
            $table->dropUnique('curricula_unique_key');
            $table->unsignedSmallInteger('term')->after('exam_type_id');
            $table->foreignId('academic_session_id')->nullable()->after('school_id')->constrained()->cascadeOnDelete();
        });

        // Reverse data migration
        $curricula = DB::table('curricula')->get();
        foreach ($curricula as $curriculum) {
            $term = DB::table('terms')->find($curriculum->term_id);
            if ($term) {
                DB::table('curricula')
                    ->where('id', $curriculum->id)
                    ->update([
                        'term' => $term->order,
                        'academic_session_id' => $term->academic_session_id
                    ]);
            }
        }

        Schema::table('curricula', function (Blueprint $table) {
            // Drop the constraint before the column it references — MySQL 1828
            // ("Cannot drop column 'term_id': needed in a foreign key constraint")
            // otherwise aborts this down() entirely. Found by the Phase-1 four-path
            // migration audit; migrate:fresh never calls down(), so this had never run.
            $table->dropForeign(['term_id']);
            $table->dropColumn('term_id');
            $table->unique(['school_id', 'academic_session_id', 'class_level_arm_id', 'term', 'exam_type_id'], 'curricula_unique_key');
        });

        Schema::table('terms', function (Blueprint $table) {
            $table->dropUnique(['academic_session_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
