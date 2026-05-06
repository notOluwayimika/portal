<?php
// database/seeders/AuditLogSeeder.php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Score;
use App\Models\User;
use Illuminate\Database\Seeder;

class AuditLogSeeder extends Seeder
{
    public function run(): void
    {
        $teacher = User::withoutGlobalScopes()
            ->where('email', 'emeka.teacher@brookstone.test')
            ->firstOrFail();

        // Log a CREATE_SCORE entry for each seeded score
        Score::all()->each(function (Score $score) use ($teacher) {
            AuditLog::record(
                userId: $teacher->id,
                action: 'CREATE_SCORE',
                entity: $score,
                payload: [
                    'student_id' => $score->student_id,
                    'curriculum_subject_id' => $score->curriculum_subject_id,
                    'marking_component_id' => $score->marking_component_id,
                    'score' => $score->score,
                ],
                ipAddress: '127.0.0.1',
            );
        });

        $this->command->info('Audit logs seeded.');
    }
}
