<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Term;
use Illuminate\Database\Seeder;

/**
 * ⚠ THIS SEEDER PRODUCES DIFFERENT DATA DEPENDING ON THE DAY IT RUNS.
 *
 * Every window below is derived from `now()->startOfYear()`, so the term dates it writes are a
 * function of the calendar, not of the seed. Two consequences worth knowing before you call it:
 *
 * 1. It is invoked FROM A MIGRATION — 2026_05_06_085734_update_terms_and_curricula_tables.php
 *    step 2 — which makes that migration non-deterministic. See the hazard note there.
 * 2. `updateOrCreate` is keyed on (academic_session_id, order), so running this against a school
 *    that already has terms OVERWRITES their real dates with generated ones.
 *
 * Term dates are load-bearing for money since S1 commit 2 (`finance_fee_schedules.term_id` is a
 * RESTRICT FK). Do not run this on an environment whose term calendar has been set by hand.
 */
class TermSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sessions = AcademicSession::all();

        foreach ($sessions as $session) {
            $terms = [
                [
                    'name' => 'First Term',
                    'slug' => 'first-term',
                    'order' => 1,
                    'start_date' => now()->startOfYear()->addMonths(8), // Sept
                    'end_date' => now()->startOfYear()->addMonths(11), // Dec
                ],
                [
                    'name' => 'Second Term',
                    'slug' => 'second-term',
                    'order' => 2,
                    'start_date' => now()->startOfYear()->addMonths(12), // Jan next year
                    'end_date' => now()->startOfYear()->addMonths(15), // April
                ],
                [
                    'name' => 'Third Term',
                    'slug' => 'third-term',
                    'order' => 3,
                    'start_date' => now()->startOfYear()->addMonths(16), // May
                    'end_date' => now()->startOfYear()->addMonths(19), // Aug
                ],
            ];

            foreach ($terms as $termData) {
                Term::updateOrCreate(
                    [
                        'academic_session_id' => $session->id,
                        'order' => $termData['order'],
                    ],
                    $termData
                );
            }
        }
    }
}
