<?php

namespace App\Console\Commands;

use App\Exceptions\Dashboard\PiiDetectedException;
use App\Models\School;
use App\Services\Dashboard\DashboardAnalysisService;
use Illuminate\Console\Command;

class DashboardAnalyzeCommand extends Command
{
    protected $signature = 'dashboard:analyze
                            {--school= : UUID or ID of the school to analyze}
                            {--all : Analyze all schools}
                            {--force : Force regeneration even if cache is fresh}';

    protected $description = 'Analyze school data and generate a dashboard analysis file';

    public function handle(DashboardAnalysisService $service): int
    {
        $schoolOption = $this->option('school');
        $all = $this->option('all');
        $force = $this->option('force');

        if (!$schoolOption && !$all) {
            $this->error('Provide --school={uuid} or --all');
            return self::FAILURE;
        }

        if ($all) {
            $schools = School::all();
            $this->info("Analyzing {$schools->count()} school(s)...");
            $failed = 0;

            foreach ($schools as $school) {
                if (!$this->runForSchool($school, $service, $force)) {
                    $failed++;
                }
            }

            if ($failed > 0) {
                $this->error("{$failed} school(s) failed analysis.");
                return self::FAILURE;
            }

            $this->info('All schools analyzed successfully.');
            return self::SUCCESS;
        }

        $school = School::where('uuid', $schoolOption)->orWhere('id', $schoolOption)->first();

        if (!$school) {
            $this->error("School not found: {$schoolOption}");
            return self::FAILURE;
        }

        return $this->runForSchool($school, $service, $force) ? self::SUCCESS : self::FAILURE;
    }

    private function runForSchool(School $school, DashboardAnalysisService $service, bool $force): bool
    {
        $this->line("Analyzing school: {$school->name} (ID: {$school->id})");

        try {
            $analysis = $service->generate($school);
            $this->info("  Active modules: {$analysis['active_modules_count']}");
            $this->info("  Onboarding state: " . ($analysis['is_onboarding_state'] ? 'yes' : 'no'));
            $this->info("  Data gaps: " . count($analysis['data_gaps']));
            $this->info("  Analyzed at: {$analysis['analyzed_at']}");
            return true;
        } catch (PiiDetectedException $e) {
            $this->error("  PII DETECTED — analysis aborted: {$e->getMessage()}");
            return false;
        } catch (\Throwable $e) {
            $this->error("  Analysis failed: {$e->getMessage()}");
            return false;
        }
    }
}
