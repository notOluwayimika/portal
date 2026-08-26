<?php

namespace App\Console\Commands;

use App\Jobs\MoveFromTermJob;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\MarkingComponent;
use App\Models\MarkingScheme;
use App\Models\Scopes\SchoolScope;
use App\Models\Score;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use Database\Seeders\CcmFoldDriveSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stands up the CCM fold surface's browser-drive fixture — the SAME builder the unit proof runs.
 *
 * {@see CcmFoldDriveSeeder} is the single definition of both worlds, and
 * `tests/Feature/CcmFoldDriveFixtureTest.php` asserts against it that leg 4's world can actually
 * make the guard abort. That ordering is the point: in a browser a fold that SUCCEEDED and a fold
 * that COULD NEVER HAVE FAILED are the same observation, so the fixture is proven red before the
 * browser is spent on it. This command only orchestrates and reports.
 *
 * ── THE GUARDS, AND WHICH ONE IS ACTUALLY LOAD-BEARING RIGHT NOW ─────────────────────────────────
 * Same two structural refusals as {@see SeedDriveFixture}: APP_ENV must be `drive`, AND the
 * database name must contain a `drive` token — an ALLOWLIST, so a name nobody anticipated
 * (`portal_demo`, `school_uat`) is refused rather than wiped, because this migrate:fresh-es.
 *
 * BUT THE ENV GUARD IS CURRENTLY INERT AND MUST NOT BE RELIED ON. The committed `.env` for the dev
 * instance at portal.test itself carries `APP_ENV=drive` against `DB_DATABASE="portal-test"`, so on
 * a developer's normal machine the env check PASSES while pointed at the dev database. The database
 * ALLOWLIST is the only thing standing between this command and that database — `portal-test`
 * carries no `drive` token (the separator is a hyphen, and the pattern requires `_drive_`,
 * `drive_`, `_drive` or exactly `drive`), so it is refused. That is a check whose failing case is
 * reachable, which is why it is the one this class leans on, and it reads the name off the LIVE
 * CONNECTION rather than off the env var so a stale `.env` cannot answer for the database actually
 * being written to.
 */
class SeedCcmFoldDrive extends Command
{
    protected $signature = 'academics:seed-ccm-fold-drive {--stage-fold-failure : Drive school B up to the moment of the refusal, so the browser gate can observe the RENDERED failure without needing score-entry permissions}';

    protected $description = 'Seed the CCM fold surface drive fixture (drive DB ONLY) — two worlds, one of which the fold must refuse';

    /** @see SeedDriveFixture::DRIVE_DB_PATTERN — deliberately identical, an allowlist not a denylist. */
    private const DRIVE_DB_PATTERN = '/(^|_)drive(_|$)/';

    public function handle(): int
    {
        if (! $this->getLaravel()->environment('drive')) {
            $this->error('REFUSED: academics:seed-ccm-fold-drive runs ONLY in APP_ENV=drive (got: '
                .$this->getLaravel()->environment().'). It migrate:fresh-es the database.');

            return self::FAILURE;
        }

        // Read off the LIVE CONNECTION, not the env var. This is the guard that is actually
        // load-bearing here — see the class docblock for why the env check above is not.
        $db = (string) DB::connection()->getDatabaseName();

        if (! preg_match(self::DRIVE_DB_PATTERN, strtolower($db))) {
            $this->error("REFUSED: database [{$db}] is not a recognised drive database — its name must contain a `drive` token (e.g. portal_drive). "
                .'This is an ALLOWLIST: anything not explicitly a drive DB is refused, so the dev database — which this very machine may be pointing APP_ENV=drive at — cannot be wiped by a command that only checked the environment.');

            return self::FAILURE;
        }

        $this->warn("CCM fold drive fixture → database [{$db}]. Wiping and reseeding from zero…");

        $this->call('migrate:fresh', ['--force' => true]);
        (new RbacSeeder)->run();

        $seeder = new CcmFoldDriveSeeder;
        $seeder->run();

        if ($this->option('stage-fold-failure')) {
            $this->stageFoldFailure($seeder);
        }

        $this->report($seeder);

        return self::SUCCESS;
    }

    /**
     * Leave school B at the exact moment before the fold refuses.
     *
     * ── WHY THIS IS STAGED RATHER THAN DRIVEN ────────────────────────────────────────────────────
     * Leg 4's subject is the RENDERED REFUSAL — the kind badge, the terminal copy, the reason with no
     * server path in it. Reaching it by hand needs the CCM arrival to exist (an end-of-term rollover)
     * and marks to sit on the CCM-only component, and marks entry is behind score-entry permissions
     * these seats do not hold. Staging it means the browser gate observes the thing it is for
     * instead of spending the session acquiring permissions to set up a precondition.
     *
     * SCHOOL A IS DELIBERATELY NOT STAGED. That is where the loop gate is driven — toggle, roll over,
     * see where the pupil lands — and pre-running any of it would hand the driver the answer.
     *
     * It runs the REAL MoveFromTermJob, not a hand-built curriculum, so the CCM arrival is the one
     * the five-key construction produces. Scores go through the real model, guards and all.
     */
    private function stageFoldFailure(CcmFoldDriveSeeder $seeder): void
    {
        $w = $seeder->schemeAsymmetric;
        $schoolId = (int) $w['school']->id;

        ActiveSchool::runFor($schoolId, function () use ($w, $schoolId) {
            (new MoveFromTermJob($w['source'], (int) $w['operator']->id, $schoolId))->handle();

            $ccm = CcmFoldDriveSeeder::arrival($w['source'], $w['terms'][3], isCcm: true);

            if (! $ccm instanceof Curriculum) {
                $this->error('STAGING FAILED: the rollover produced no CCM arrival — leg 4 is not drivable.');

                return;
            }

            $subject = CurriculumSubject::where('curriculum_id', $ccm->id)->firstOrFail();
            $component = $subject->effectiveMarkingComponents()->firstWhere('name', 'Half Term Project');

            if ($component === null) {
                $this->error('STAGING FAILED: the CCM-only component is not on the arrival — the fold would SUCCEED and leg 4 would prove nothing.');

                return;
            }

            foreach (StudentCurriculum::withoutGlobalScopes()->where('curriculum_id', $ccm->id)->get() as $episode) {
                Score::firstOrCreate([
                    'student_id' => $episode->student_id,
                    'curriculum_subject_id' => $subject->id,
                    'marking_component_id' => $component->id,
                ], ['score' => 18, 'created_by' => $w['operator']->id]);
            }

            $scored = Score::where('marking_component_id', $component->id)->count();

            $this->newLine();
            $this->warn("STAGED: school#{$schoolId} curriculum#{$ccm->id} is CCM and holds {$scored} mark(s) on "
                ."\"{$component->name}\" (component#{$component->id}), which has NO non-CCM counterpart. "
                .'The gate is UP and the fold will REFUSE — that refusal is what the browser gate reads.');
        });
    }

    /**
     * What the drive needs to know BEFORE it opens a browser.
     *
     * Counted from the DATABASE, never from the seeder's own variables — those would only ever
     * report what the seeder intended. A zero in any column means the leg that depends on it cannot
     * be driven, and the drive is worthless before it starts.
     */
    private function report(CcmFoldDriveSeeder $seeder): void
    {
        $rows = [];

        foreach ([
            ['A — legs 1-3 (subject-local, fold SUCCEEDS)', $seeder->subjectLocal],
            ['B — leg 4 (scheme-asymmetric, fold REFUSES)', $seeder->schemeAsymmetric],
        ] as [$label, $world]) {
            $schoolId = (int) $world['school']->id;

            $rows[] = [
                $label,
                'school#'.$schoolId,
                Curriculum::withoutGlobalScope(SchoolScope::class)->where('school_id', $schoolId)->count(),
                ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)->where('school_id', $schoolId)->count(),
                // THE FLAG THE WHOLE DRIVE TURNS ON. Zero here and no pupil can land in CCM at all.
                ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
                    ->where('school_id', $schoolId)->where('is_ccm', true)->count(),
                StudentCurriculum::withoutGlobalScopes()
                    ->whereIn('curriculum_id', Curriculum::withoutGlobalScope(SchoolScope::class)
                        ->where('school_id', $schoolId)->select('id'))
                    ->count(),
                MarkingScheme::where('school_id', $schoolId)->where('status', 'active')->count(),
                // Global (school-wide) templates — the non-CCM side of a subject-local fold is
                // built from THESE, not from a copy of the CCM subject's components. School A
                // folding at all depends on this being non-zero; the first run of the unit proof
                // refused because it was.
                MarkingComponent::withoutGlobalScope(SchoolScope::class)
                    ->where('school_id', $schoolId)->global()->count(),
            ];
        }

        $this->newLine();
        $this->table([
            'World', 'School', 'Curricula', 'Slots', 'CCM slots', 'Episodes', 'Active schemes', 'Global templates',
        ], $rows);

        // TWO ZEROS IN THAT TABLE ARE STRUCTURAL AND MUST NOT BE READ AS AN ABORT. The drive rule is
        // "a zero means the screen cannot author anything" — these two are the exceptions, and they
        // are the exact axis the two worlds differ on, so a fixture WITHOUT them would be the broken
        // one. Said here rather than left to be rediscovered.
        $this->line('  School A `Active schemes` = 0 BY CONSTRUCTION: it is the subject-local world, and schemes are keyed');
        $this->line('  (school, is_ccm, version) SCHOOL-WIDE — an active CCM scheme here would attach itself to the arrival');
        $this->line('  in legs 1-3 and make that fold refuse too. School B `Global templates` = 0 for the mirror reason: its');
        $this->line('  non-CCM side is built from its scheme, so the global templates are never consulted. Every OTHER zero');
        $this->line('  in that table is a stop.');

        $b = $seeder->schemeAsymmetric;

        $this->newLine();
        $this->line('THE ASYMMETRY leg 4 turns on — school#'.$b['school']->id.', the only configuration in which the fold can refuse:');

        foreach ([['non-CCM', $b['nonCcmScheme']], ['CCM', $b['ccmScheme']]] as [$kind, $scheme]) {
            $names = MarkingComponent::withoutGlobalScope(SchoolScope::class)
                ->where('marking_scheme_id', $scheme->id)->orderBy('id')->pluck('name')->all();

            $this->line(sprintf('  %-8s scheme#%d: %s', $kind, $scheme->id, json_encode($names)));
        }

        $this->line('  → "'.$b['ccmOnlyComponent']->name.'" (component#'.$b['ccmOnlyComponent']->id.') exists on the CCM side ONLY. '
            .'Once leg 2 puts a mark on it, the fold must REFUSE rather than drop it.');

        $a = $seeder->subjectLocal;

        $this->newLine();
        $this->line('SEATS (password: '.CcmFoldDriveSeeder::PASSWORD.') — all hold school access; the ABILITIES differ and that is the point:');
        $this->line('  '.str_pad($a['setupOperator']->email, 38).' school#'.$a['school']->id.'  academic_setup.manage + academics.rollover');
        $this->line('     ^ the ONLY seat that can see the CCM checkbox — it is gated on academic_setup.manage in the panel');
        $this->line('       AND on the route group. Drives the whole loop in one login: toggle, roll over, see the landing.');
        $this->line('  '.str_pad($a['operator']->email, 38).' school#'.$a['school']->id.'  academics.rollover ONLY');
        $this->line('     ^ the NEGATIVE authorization observation: same screen, no CCM control rendered on it.');
        $this->line('  '.str_pad($seeder->schemeAsymmetric['operator']->email, 38).' school#'.$seeder->schemeAsymmetric['school']->id.'  academics.rollover ONLY');
        $this->line('     ^ leg 4. Needs no setup ability: its CCM slot is configured by the seeder.');

        $this->newLine();

        if ($this->option('stage-fold-failure')) {
            $this->line('SCHOOL A IS PRISTINE — nothing rolled over. That is where the loop gate is driven, and pre-running');
            $this->line('any of it would hand the driver the answer.');
            $this->line('SCHOOL B IS STAGED to the moment before the refusal (see the STAGED line above): the CCM arrival');
            $this->line('exists and carries marks on the counterpart-less component, so the gate is UP and Fold will REFUSE.');
        } else {
            $this->line('Both worlds start with pupils in slot 1 and NOTHING rolled over yet — leg 1 is the operator running the');
            $this->line('end-of-term rollover, which is what builds the CCM arrival. The gate is not up until then.');
            $this->line('For the browser gate, re-run with --stage-fold-failure so leg 4 does not need score-entry permissions.');
        }
    }
}
