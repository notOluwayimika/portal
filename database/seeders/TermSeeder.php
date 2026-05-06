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
                ],
                [
                    'name' => 'Second Term',
                    'slug' => 'second-term',
                    'order' => 2,
                ],
                [
                    'name' => 'Third Term',
                    'slug' => 'third-term',
                    'order' => 3,
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
