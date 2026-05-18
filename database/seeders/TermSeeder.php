<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Term;
use Illuminate\Database\Seeder;

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
