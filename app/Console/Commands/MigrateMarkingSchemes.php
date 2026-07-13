<?php

namespace App\Console\Commands;

use App\Models\Curriculum;
use App\Models\MarkingComponent;
use App\Models\MarkingScheme;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateMarkingSchemes extends Command
{
    protected $signature = 'marking-schemes:migrate
        {--school= : Limit migration to a numeric school ID}
        {--apply : Persist changes; without this option the command is a dry run}
        {--delete-legacy : Delete local components after all score references are remapped}';

    protected $description = 'Consolidate identical curriculum-subject marking components into immutable curriculum schemes';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $query = Curriculum::query()->withoutGlobalScopes()->with([
            'curriculumSubjects.markingComponents',
        ])->orderBy('id');

        if ($schoolId = $this->option('school')) {
            $query->where('school_id', (int) $schoolId);
        }

        $migrated = 0;
        $skipped = 0;

        $query->chunkById(100, function ($curricula) use ($apply, &$migrated, &$skipped) {
            foreach ($curricula as $curriculum) {
                if ($curriculum->marking_scheme_id) {
                    continue;
                }

                $sets = $curriculum->curriculumSubjects
                    ->map(fn ($subject) => $subject->markingComponents)
                    ->filter(fn (Collection $components) => $components->isNotEmpty());

                if ($sets->isEmpty()) {
                    $this->warn("Curriculum {$curriculum->id}: no local components; skipped.");
                    $skipped++;

                    continue;
                }

                $signatures = $sets->map(fn (Collection $components) => $this->signatureFor($components))->unique();
                if ($signatures->count() !== 1 || $sets->count() !== $curriculum->curriculumSubjects->count()) {
                    $this->warn("Curriculum {$curriculum->id}: subjects have different or missing component sets; left in legacy mode.");
                    $skipped++;

                    continue;
                }

                $this->line("Curriculum {$curriculum->id}: {$sets->first()->count()} components can be consolidated.");
                if (! $apply) {
                    $migrated++;

                    continue;
                }

                DB::transaction(fn () => $this->migrateCurriculum($curriculum, $sets->first()));
                $migrated++;
            }
        });

        $mode = $apply ? 'Migrated' : 'Dry run matched';
        $this->info("{$mode} {$migrated} curricula; skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function migrateCurriculum(Curriculum $curriculum, Collection $template): void
    {
        $signature = $this->signatureFor($template);
        $scheme = MarkingScheme::with('components')
            ->where('school_id', $curriculum->school_id)
            ->where('is_ccm', $curriculum->is_ccm)
            ->get()
            ->first(fn (MarkingScheme $candidate) => $this->signatureFor($candidate->components) === $signature);

        if (! $scheme) {
            $version = ((int) MarkingScheme::where('school_id', $curriculum->school_id)
                ->where('is_ccm', $curriculum->is_ccm)->max('version')) + 1;

            $scheme = MarkingScheme::create([
                'school_id' => $curriculum->school_id,
                'is_ccm' => $curriculum->is_ccm,
                'version' => $version,
                'status' => 'retired',
            ]);

            foreach ($template as $component) {
                $scheme->components()->create([
                    'school_id' => $curriculum->school_id,
                    'curriculum_subject_id' => null,
                    'name' => $component->name,
                    'weight' => $component->weight,
                    'is_ccm' => $curriculum->is_ccm,
                ]);
            }
            $scheme->load('components');
        }

        $schemeComponents = $scheme->components->mapWithKeys(
            fn (MarkingComponent $component) => [$this->componentKey($component) => $component]
        );

        foreach ($curriculum->curriculumSubjects as $subject) {
            foreach ($subject->markingComponents as $legacy) {
                $replacement = $schemeComponents->get($this->componentKey($legacy));
                DB::table('scores')
                    ->where('curriculum_subject_id', $subject->id)
                    ->where('marking_component_id', $legacy->id)
                    ->update(['marking_component_id' => $replacement->id]);
            }
        }

        $curriculum->update(['marking_scheme_id' => $scheme->id]);

        if ($this->option('delete-legacy')) {
            MarkingComponent::whereIn('curriculum_subject_id', $curriculum->curriculumSubjects->pluck('id'))
                ->whereDoesntHave('scores')
                ->delete();
        }
    }

    private function signatureFor(Collection $components): string
    {
        return $components->map(fn ($component) => $this->componentKey($component))->sort()->implode('|');
    }

    private function componentKey(MarkingComponent $component): string
    {
        return Str::lower(trim($component->name)).':'.number_format((float) $component->weight, 3, '.', '');
    }
}
