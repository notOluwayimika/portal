<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\MarkingComponent;
use App\Models\MarkingScheme;
use App\Models\Permission;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The CCM fold surface's drive fixture — and, deliberately, the SAME builder the unit proof runs.
 *
 * ── WHY THIS IS A SEEDER AND NOT A PEST HELPER ───────────────────────────────────────────────────
 * The failing leg of this drive (leg 4) needs a world the guard can actually abort on, and a fixture
 * built slightly wrong is INDISTINGUISHABLE FROM ONE BUILT RIGHT IN A BROWSER: a fold that succeeds
 * and a fold that could never have failed both render as a green batch over a cleared gate. So the
 * fixture has to be proven red at the unit level before a browser is spent on it — and a Pest helper
 * proving a LOOKALIKE of the drive fixture proves nothing about the drive fixture. One builder, two
 * callers: `tests/Feature/CcmFoldDriveFixtureTest.php` asserts the guard fires on what it builds,
 * and `academics:seed-ccm-fold-drive` hands the identical shape to the browser.
 *
 * ── TWO WORLDS, GENUINELY DIFFERENT, AND IN TWO SCHOOLS ON PURPOSE ───────────────────────────────
 * Legs 1-3 (the SUCCESS legs) are subject-local: no marking schemes at all, so the fold runs the
 * legacy path where cloneCurriculumSubjects copies the component names and the matcher is handed
 * matches BY CONSTRUCTION. The guard cannot fire there, which is correct for a success leg.
 *
 * Leg 4 (the REFUSAL leg) is scheme-backed: two ACTIVE marking schemes, one CCM and one not, with a
 * CCM-only component that has no non-CCM counterpart. It is the ONLY configuration in which the
 * guard can fire — and marking schemes are keyed on (school, is_ccm, version) SCHOOL-WIDE, so an
 * active CCM scheme in school A would attach itself to legs 1-3's CCM arrival too and destroy the
 * subject-local separation. The two worlds are therefore two schools, not two class levels.
 *
 * ── THE SHAPE IS END-OF-TERM, AND THE FLAG IS WHAT IS BEING PROVEN ───────────────────────────────
 * Every level runs slots [1, 3] with pupils sitting in slot 1, so the end-of-term rollover moves
 * them into slot 3 — the next participating slot — and `is_ccm` on THAT participation row decides
 * whether they land in a CCM curriculum. School A carries a SIBLING level with the same movement and
 * the flag OFF, so the assertion proves the flag decides the landing rather than the rollover doing
 * it. Without the sibling, "pupils arrived in a CCM curriculum" is equally explained by a rollover
 * that lands everyone in CCM regardless of configuration.
 */
class CcmFoldDriveSeeder extends Seeder
{
    /** Drive seats only. The suite never logs in as these — it calls the builders directly. */
    public const PASSWORD = 'ccm-fold-drive';

    /** @var array<string, mixed> school A — subject-local, legs 1-3 */
    public array $subjectLocal = [];

    /** @var array<string, mixed> school B — scheme-asymmetric, leg 4 */
    public array $schemeAsymmetric = [];

    public function run(): void
    {
        $this->subjectLocal = $this->seedSubjectLocalWorld();
        $this->schemeAsymmetric = $this->seedSchemeAsymmetricWorld();
    }

    /**
     * SCHOOL A — legs 1-3. Two levels, same movement, `is_ccm` differing on the landing slot.
     *
     * No marking scheme exists in this school at all, so both the arrival and the fold run the
     * legacy subject-local path: cloneCurriculumSubjects copies each component by name, which is
     * exactly why the fold here succeeds and the guard is not the thing under test.
     *
     * @return array<string, mixed>
     */
    public function seedSubjectLocalWorld(): array
    {
        $school = $this->makeSchool('CCM Fold Drive — Subject-local');

        return ActiveSchool::runFor((int) $school->id, function () use ($school) {
            $operator = $this->makeOperator($school, 'operator');
            // ── THE SEAT THAT CAN ACTUALLY REACH THE TOGGLE ────────────────────────────────────
            // The CCM checkbox is gated on `academic_setup.manage` in BOTH the panel
            // (progression-panel.tsx) and the route group (routes/api.php), and $operator holds
            // `academics.rollover` and deliberately NOT that. So the browser gate — set slot 3 CCM
            // through the panel, roll over, watch where the pupil lands — is UNDRIVABLE with the
            // rollover seat: the control simply is not rendered. Found by checking the seat's
            // abilities before handing the fixture over, not by a driver losing a session to it.
            //
            // It carries `academics.rollover` TOO, so one login drives the whole loop rather than
            // forcing a seat switch mid-flow. $operator stays rollover-only on purpose: it is the
            // negative authorization observation — the same screen, with no CCM control on it.
            $setupOperator = $this->makeOperator($school, 'setup', withAcademicSetup: true);
            $examType = $this->makeExamType($school);
            [$session, $terms] = $this->makeSession($school, [1, 2, 3, 4]);

            // ── WHY SLOT 4 EXISTS, AND WHY THE LEVELS SKIP SLOT 2 ───────────────────────────────
            // Slot 2 is skipped deliberately: NextTermSlot resolves the next PARTICIPATING slot,
            // not the next term, so 1 -> 3 proves that resolution rather than "the term after this
            // one". Slot 4 is there because leg 3 ends with "the gate clears and the rollover
            // PROCEEDS" — and a level whose participation ended at slot 3 has nowhere to proceed
            // to, so that clause would be unreachable by construction and the leg would quietly
            // degrade into "the gate clears", with nothing to notice the difference.
            $ccmArm = $this->makeLevel($school, 'Year 7', 7, slots: [1, 3, 4], ccmSlots: [3]);
            // THE NEGATIVE ARM: identical movement, flag OFF. Its landing must be non-CCM.
            $plainArm = $this->makeLevel($school, 'Year 8', 8, slots: [1, 3, 4], ccmSlots: []);

            $ccmSource = $this->makeSourceCurriculum($school, $ccmArm['arm'], $terms[1], $examType);
            $plainSource = $this->makeSourceCurriculum($school, $plainArm['arm'], $terms[1], $examType);

            // ── THE GLOBAL TEMPLATES, AND WHY THIS FIXTURE IS WRONG WITHOUT THEM ────────────────
            // MoveFromCcmJob does NOT copy the CCM subject's own components onto the non-CCM side —
            // with no marking scheme it seeds them from `MarkingComponent::global()`, the school's
            // template rows (curriculum_subject_id AND marking_scheme_id both NULL). Omit these and
            // the non-CCM subject is built with ZERO components, so every scored CCM component is
            // unmatched and legs 1-3 REFUSE — which is what the first run of the unit proof found.
            // "Matched by construction" is these templates and the subject-local copies of them
            // agreeing by name, which is how a configured school actually reaches that state.
            $componentNames = [['Continuous Assessment', 0.4], ['Examination', 0.6]];

            foreach ($componentNames as [$name, $weight]) {
                $this->makeComponent($school, $name, $weight, isCcm: false);
            }

            // Subject-local components on BOTH sources, copied from the templates above by name.
            foreach ([$ccmSource, $plainSource] as $source) {
                $subject = $this->makeCurriculumSubject($school, $source, 'Mathematics');

                foreach ($componentNames as [$name, $weight]) {
                    $this->makeComponent($school, $name, $weight, isCcm: false, curriculumSubject: $subject);
                }
            }

            $ccmPupils = $this->enroll($school, $ccmSource, 2, 'Ccm');
            $plainPupils = $this->enroll($school, $plainSource, 1, 'Plain');

            return compact('school', 'operator', 'setupOperator', 'examType', 'session', 'terms')
                + ['ccmLevel' => $ccmArm['level'], 'ccmArm' => $ccmArm['arm'], 'ccmSource' => $ccmSource, 'ccmPupils' => $ccmPupils]
                + ['plainLevel' => $plainArm['level'], 'plainArm' => $plainArm['arm'], 'plainSource' => $plainSource, 'plainPupils' => $plainPupils];
        });
    }

    /**
     * SCHOOL B — leg 4. The only configuration in which the fold can refuse.
     *
     * Two ACTIVE schemes. The CCM one carries a component the non-CCM one does not, so once marks
     * are entered against it the fold's matcher finds no counterpart and the guard aborts rather
     * than dropping the marks.
     *
     * THE ASYMMETRY IS ONE COMPONENT AND NOTHING ELSE. "Continuous Assessment" exists on both sides
     * so the fixture is not degenerate: a guard that refused on SHAPE — any difference between the
     * two schemes — would pass an all-unmatched fixture for the wrong reason, and the positive
     * control in the unit proof adds the missing counterpart and nothing else.
     *
     * @return array<string, mixed>
     */
    public function seedSchemeAsymmetricWorld(): array
    {
        $school = $this->makeSchool('CCM Fold Drive — Scheme-asymmetric');

        return ActiveSchool::runFor((int) $school->id, function () use ($school) {
            $operator = $this->makeOperator($school, 'operator-scheme');
            $examType = $this->makeExamType($school);
            [$session, $terms] = $this->makeSession($school, [1, 3]);

            $nonCcmScheme = MarkingScheme::create([
                'school_id' => $school->id, 'is_ccm' => false, 'version' => 1, 'status' => 'active',
            ]);
            $ccmScheme = MarkingScheme::create([
                'school_id' => $school->id, 'is_ccm' => true, 'version' => 1, 'status' => 'active',
            ]);

            $this->makeComponent($school, 'Continuous Assessment', 0.4, isCcm: false, scheme: $nonCcmScheme);
            $this->makeComponent($school, 'Examination', 0.6, isCcm: false, scheme: $nonCcmScheme);

            // The shared component — present on both sides, so the guard has something to MATCH.
            $this->makeComponent($school, 'Continuous Assessment', 0.5, isCcm: true, scheme: $ccmScheme);
            // THE CCM-ONLY COMPONENT. No counterpart on the non-CCM side; once it carries a mark the
            // fold must refuse rather than fold over it.
            $ccmOnly = $this->makeComponent($school, 'Half Term Project', 0.5, isCcm: true, scheme: $ccmScheme);

            $level = $this->makeLevel($school, 'Year 9', 9, slots: [1, 3], ccmSlots: [3]);

            // The source carries the NON-CCM scheme, as a class being marked on full-term weights
            // does. MoveFromTermJob deliberately does not copy it into a CCM target — it resolves
            // the CCM scheme itself — and that is the path this world exercises.
            $source = $this->makeSourceCurriculum($school, $level['arm'], $terms[1], $examType, $nonCcmScheme);
            $sourceSubject = $this->makeCurriculumSubject($school, $source, 'Mathematics');

            $pupils = $this->enroll($school, $source, 2, 'Scheme');

            return compact('school', 'operator', 'examType', 'session', 'terms', 'source', 'sourceSubject', 'pupils')
                + ['level' => $level['level'], 'arm' => $level['arm']]
                + ['nonCcmScheme' => $nonCcmScheme, 'ccmScheme' => $ccmScheme, 'ccmOnlyComponent' => $ccmOnly];
        });
    }

    // -----------------------------------------------------------------------
    // Builders
    // -----------------------------------------------------------------------

    private function makeSchool(string $name): School
    {
        return School::create(['name' => $name, 'slug' => (string) Str::uuid()]);
    }

    /**
     * A seat holding `academics.rollover` and school ACCESS, and not academic_setup.manage.
     *
     * Mirrors the suite's `rollover_grant`: the permission exists precisely because it is not the
     * config permission, so borrowing an `admin` seat would hand the operator both and make the
     * drive's authorization observation vacuous.
     */
    private function makeOperator(School $school, string $localPart, bool $withAcademicSetup = false): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Drive',
            'last_name' => 'Operator',
            'email' => $localPart.'@ccm-fold.drive.test',
            'password' => bcrypt(self::PASSWORD),
            'school_id' => $school->id,
            'two_factor_confirmed_at' => now(),
            'email_verified_at' => now(),
        ]);

        $permission = Permission::where('name', \App\Enums\Permission::ACADEMICS_ROLLOVER->value)
            ->where('guard_name', 'web')
            ->first()
            ?? Permission::create([
                'name' => \App\Enums\Permission::ACADEMICS_ROLLOVER->value,
                'guard_name' => 'web',
            ]);

        $user->grantSchoolAccess($school, 'registrar');

        setPermissionsTeamId($school->id);
        $user->givePermissionTo($permission);

        if ($withAcademicSetup) {
            // firstOrCreate, matching the rollover permission above: the suite does not run
            // RbacSeeder, so firstOrFail() here reds every arm that builds this world — which is
            // exactly what it did on the first run after this seat was added.
            $user->givePermissionTo(
                Permission::where('name', \App\Enums\Permission::ACADEMIC_SETUP_MANAGE->value)
                    ->where('guard_name', 'web')
                    ->first()
                    ?? Permission::create([
                        'name' => \App\Enums\Permission::ACADEMIC_SETUP_MANAGE->value,
                        'guard_name' => 'web',
                    ])
            );
        }

        $user->flushSchoolAccessCache();

        return $user;
    }

    private function makeExamType(School $school): ExamType
    {
        return ExamType::create([
            'school_id' => $school->id,
            'name' => 'Internal Exam',
            'slug' => 'exam-'.Str::random(8),
        ]);
    }

    /**
     * A CURRENT session and its terms, keyed by order.
     *
     * Slot 1 is ACTIVE (the closing term) and every later slot UPCOMING — NextTermSlot allowlists
     * exactly those two statuses as promotion targets, so a term left in any other status makes the
     * rollover refuse for a reason that has nothing to do with CCM.
     *
     * @param  list<int>  $orders
     * @return array{0: AcademicSession, 1: array<int, Term>}
     */
    private function makeSession(School $school, array $orders): array
    {
        $session = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2026/2027',
            'slug' => 'sess-'.Str::random(8),
            'is_current' => true,
        ]);

        $terms = [];

        foreach ($orders as $order) {
            $terms[$order] = Term::create([
                'academic_session_id' => $session->id,
                'school_id' => $school->id,
                'name' => "Term {$order}",
                'slug' => 'term-'.Str::random(8),
                'order' => $order,
                'start_date' => now()->addMonths($order * 3),
                'end_date' => now()->addMonths($order * 3 + 2),
                'status' => $order === 1 ? 'active' : 'upcoming',
            ]);
        }

        return [$session, $terms];
    }

    /**
     * A class level, its participation rows, and one arm.
     *
     * `is_ccm` is set per slot from $ccmSlots — which is the whole point of holding CCM
     * participation at (class level, term slot) granularity rather than on the curriculum.
     *
     * @param  list<int>  $slots
     * @param  list<int>  $ccmSlots
     * @return array{level: ClassLevel, arm: ClassLevelArm}
     */
    private function makeLevel(School $school, string $name, int $order, array $slots, array $ccmSlots): array
    {
        $level = ClassLevel::forceCreate([
            'school_id' => $school->id, 'name' => $name, 'order' => $order,
        ]);

        foreach ($slots as $slot) {
            ClassLevelTermParticipation::forceCreate([
                'school_id' => $school->id,
                'class_level_id' => $level->id,
                'term_order' => $slot,
                'is_ccm' => in_array($slot, $ccmSlots, true),
            ]);
        }

        $arm = ClassLevelArm::forceCreate([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::firstOrCreate(['school_id' => $school->id, 'label' => 'A'])->id,
        ]);

        return ['level' => $level, 'arm' => $arm];
    }

    private function makeSourceCurriculum(
        School $school,
        ClassLevelArm $arm,
        Term $term,
        ExamType $examType,
        ?MarkingScheme $scheme = null,
    ): Curriculum {
        return Curriculum::create([
            'school_id' => $school->id,
            'term_id' => $term->id,
            'class_level_arm_id' => $arm->id,
            'exam_type_id' => $examType->id,
            'status' => 'active',
            'is_ccm' => false,
            'min_subjects' => 1,
            'marking_scheme_id' => $scheme?->id,
        ]);
    }

    private function makeCurriculumSubject(School $school, Curriculum $curriculum, string $subjectName): CurriculumSubject
    {
        // firstOrCreate, not create: `subjects` is UNIQUE on (school_id, name), and school A builds
        // this subject twice — once for the CCM arm and once for the sibling negative arm. Two class
        // levels teaching the same subject is also what a school actually looks like.
        $subject = Subject::firstOrCreate(['school_id' => $school->id, 'name' => $subjectName]);

        return CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'is_compulsory' => true,
        ]);
    }

    private function makeComponent(
        School $school,
        string $name,
        float $weight,
        bool $isCcm,
        ?CurriculumSubject $curriculumSubject = null,
        ?MarkingScheme $scheme = null,
    ): MarkingComponent {
        return MarkingComponent::create([
            'curriculum_subject_id' => $curriculumSubject?->id,
            'marking_scheme_id' => $scheme?->id,
            'school_id' => $school->id,
            'name' => $name,
            'weight' => $weight,
            'is_ccm' => $isCcm,
        ]);
    }

    /**
     * @return list<Student>
     */
    private function enroll(School $school, Curriculum $curriculum, int $count, string $tag): array
    {
        $students = [];

        for ($i = 1; $i <= $count; $i++) {
            $student = Student::create([
                'school_id' => $school->id,
                'first_name' => $tag,
                'last_name' => 'Pupil '.$i,
                'gender' => $i % 2 === 0 ? 'female' : 'male',
                'admission_number' => 'ADM-'.Str::random(8),
            ]);

            StudentCurriculum::create([
                'student_id' => $student->id,
                'curriculum_id' => $curriculum->id,
                'status' => 'active',
            ]);

            $students[] = $student;
        }

        return $students;
    }

    /**
     * The CCM curriculum this level's pupils landed in, resolved on the five-key construction the
     * jobs themselves use — never by "the newest curriculum", which would pass whatever arrived.
     */
    public static function arrival(Curriculum $source, Term $targetTerm, bool $isCcm): ?Curriculum
    {
        return Curriculum::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $source->school_id)
            ->where('term_id', $targetTerm->id)
            ->where('class_level_arm_id', $source->class_level_arm_id)
            ->where('exam_type_id', $source->exam_type_id)
            ->where('is_ccm', $isCcm)
            ->first();
    }
}
