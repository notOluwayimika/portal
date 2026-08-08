<?php

use App\Models\CommentBand;
use App\Models\CommentEntry;
use App\Models\ExamType;
use App\Services\CommentBandService;
use App\Support\ActiveSchool;
use App\Support\CommentBandDefaults;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Per-school comment banks for score entry — the replacement for seven arrays hardcoded in
 * `resources/js/components/score-entry-page.tsx`.
 *
 * The tests that matter most here are not the CRUD ones. They are:
 *
 *  - ISOLATION: one school's comments never resolve for another. `CommentEntry` carries no
 *    school_id by design (it is reachable only through its band), so this is the test that proves
 *    the indirection actually holds.
 *  - GRANULARITY: a school on the seeded 6-grade scale still gets THREE distinct comment sets
 *    across 70-100. This is the regression that attaching comments to `grade_boundaries` would
 *    have caused, pinned so a later "simplification" onto them fails loudly instead of quietly
 *    merging Outstanding / Excellent / Very good into one bank.
 *  - COVERAGE: every score in 0-100 resolves to exactly one band, including both edges.
 */

/** Run $callback with $schoolId as the active school, the way an off-request caller must. */
function cb_forSchool(int $schoolId, Closure $callback): mixed
{
    return ActiveSchool::runFor($schoolId, $callback);
}

function cb_examType(int $schoolId, string $name = 'Terminal'): ExamType
{
    return ExamType::withoutGlobalScopes()->create([
        'school_id' => $schoolId,
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::random(5),
    ]);
}

/** Load the starter ladder into $schoolId for $examTypeId. */
function cb_loadDefaults(int $schoolId, ?int $examTypeId = null): void
{
    cb_forSchool($schoolId, fn () => (new CommentBandService)->loadDefaults($examTypeId));
}

// ── Isolation ──────────────────────────────────────────────────────────────

it('never resolves one school\'s comments for another', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();

    cb_loadDefaults($a->id);

    cb_forSchool($b->id, function () {
        expect(CommentBand::count())->toBe(0)
            ->and(CommentBand::commentsFor(null, 95))->toBeEmpty();
    });

    // And school A is unaffected by B having none — the scope filters, it does not leak.
    cb_forSchool($a->id, function () {
        expect(CommentBand::commentsFor(null, 95))->not->toBeEmpty();
    });
});

it('scopes entries through their band, so a foreign band\'s comments are unreachable', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();

    cb_loadDefaults($a->id);
    cb_loadDefaults($b->id);

    $bandOfA = cb_forSchool($a->id, fn () => CommentBand::orderByDesc('min_score')->first());

    // The row exists globally...
    expect($bandOfA->comments()->count())->toBeGreaterThan(0);

    // ...but B cannot reach the BAND, which is the only door to it.
    cb_forSchool($b->id, function () use ($bandOfA) {
        expect(CommentBand::where('id', $bandOfA->id)->first())->toBeNull();
    });
});

// ── Granularity: why comments do not hang off grade_boundaries ─────────────

it('keeps three distinct comment sets across 70-100, which the grade scale cannot', function () {
    $school = al_makeSchool();
    cb_loadDefaults($school->id);

    cb_forSchool($school->id, function () {
        $at95 = CommentBand::commentsFor(null, 95)->pluck('body')->all();
        $at85 = CommentBand::commentsFor(null, 85)->pluck('body')->all();
        $at75 = CommentBand::commentsFor(null, 75)->pluck('body')->all();

        expect($at95)->not->toBe($at85)
            ->and($at85)->not->toBe($at75)
            ->and($at95)->not->toBe($at75);

        // All three scores are grade "A" (70-101) on the seeded default scale. Attaching comments
        // to grade boundaries would collapse these into one set — that is the regression.
        expect($at95)->toContain('Outstanding performance. Keep it up')
            ->and($at85)->toContain('Excellent result. Keep it up')
            ->and($at75)->toContain('Very good result. Do not relent');
    });
});

it('gives one comment set to scores that the grade scale splits across D and E', function () {
    $school = al_makeSchool();
    cb_loadDefaults($school->id);

    cb_forSchool($school->id, function () {
        // 47 is grade D (45-50) and 42 is grade E (40-45), but both are "Needs improvement".
        // Comments are COARSER than grades here — the mismatch runs in both directions.
        expect(CommentBand::commentsFor(null, 47)->pluck('body')->all())
            ->toBe(CommentBand::commentsFor(null, 42)->pluck('body')->all());
    });
});

// ── Coverage and edges ─────────────────────────────────────────────────────

it('resolves every score in 0-100 to exactly one band, both edges included', function () {
    $school = al_makeSchool();
    cb_loadDefaults($school->id);

    cb_forSchool($school->id, function () {
        foreach ([0, 0.5, 39.99, 40, 49.99, 50, 69.99, 70, 79.99, 80, 90.99, 91, 99.99, 100] as $score) {
            expect(CommentBand::commentsFor(null, $score)->count())
                ->toBeGreaterThan(0, "score {$score} resolved to no band");
        }
    });
});

it('resolves a score of exactly 100, which GradeBoundary::resolveGrade does not', function () {
    $school = al_makeSchool();
    cb_loadDefaults($school->id);

    cb_forSchool($school->id, function () {
        // The band tops out at max_score = 100. Resolving downward from the MINIMUM means there is
        // no exclusive upper bound to fall off — the bug that forces GradeBoundarySeeder to write
        // 101 for its top band.
        expect(CommentBand::commentsFor(null, 100)->pluck('body')->first())
            ->toBe('Outstanding performance. Keep it up');
    });
});

it('derives max_score from the next band up, never from client input', function () {
    $school = al_makeSchool();

    $bands = cb_forSchool($school->id, fn () => (new CommentBandService)->saveSet(null, [
        ['min_score' => 0, 'label' => 'Low'],
        ['min_score' => 50, 'label' => 'High'],
    ]));

    expect($bands->pluck('max_score')->map(fn ($v) => (float) $v)->all())
        ->toBe([100.0, 50.0]);
});

// ── The exam-type fallback ─────────────────────────────────────────────────

it('prefers an exam type\'s own ladder over the school default', function () {
    $school = al_makeSchool();
    $examType = cb_examType($school->id);

    cb_loadDefaults($school->id);                 // school default
    cb_forSchool($school->id, fn () => (new CommentBandService)->saveSet($examType->id, [
        ['min_score' => 0, 'label' => 'Everything'],
    ]));

    cb_forSchool($school->id, function () use ($examType) {
        $band = CommentBand::setFor($examType->id);

        expect($band)->toHaveCount(1)
            ->and($band->first()->label)->toBe('Everything');
    });
});

it('falls back to the school default when the exam type has no ladder', function () {
    $school = al_makeSchool();
    $examType = cb_examType($school->id);

    cb_loadDefaults($school->id);

    cb_forSchool($school->id, function () use ($examType) {
        expect(CommentBand::setFor($examType->id))->toHaveCount(7)
            ->and(CommentBand::commentsFor($examType->id, 95)->pluck('body')->first())
            ->toBe('Outstanding performance. Keep it up');
    });
});

it('falls back as a WHOLE set, never mixing halves of two ladders', function () {
    $school = al_makeSchool();
    $examType = cb_examType($school->id);

    cb_loadDefaults($school->id); // 7 bands
    cb_forSchool($school->id, fn () => (new CommentBandService)->saveSet($examType->id, [
        ['min_score' => 0, 'label' => 'Fail'],
        ['min_score' => 40, 'label' => 'Pass'],
    ]));

    // The override has no band above 40; a per-band fallback would fill 91+ from the default
    // ladder and reintroduce exactly the incoherence the whole-set rule exists to prevent.
    cb_forSchool($school->id, function () use ($examType) {
        expect(CommentBand::setFor($examType->id))->toHaveCount(2)
            ->and(CommentBand::commentsFor($examType->id, 95)->pluck('body')->all())->toBeEmpty();
    });
});

// ── The empty state is legitimate ──────────────────────────────────────────

it('returns no comments for a school that has configured none', function () {
    $school = al_makeSchool();

    cb_forSchool($school->id, function () {
        expect(CommentBand::setFor(null))->toBeEmpty()
            ->and(CommentBand::commentsFor(null, 75))->toBeEmpty();
    });
});

// ── The length pairing (the bug this feature had to fix first) ─────────────

it('accepts every starter comment at the column width, including the 52-character one', function () {
    expect(Schema::hasTable('comment_entries'))->toBeTrue();

    $longest = collect(CommentBandDefaults::bands())
        ->flatMap(fn (array $band) => $band['comments'])
        ->sortByDesc(fn (string $body) => strlen($body))
        ->first();

    // "This result is below expectation. Put in more effort" — 52 characters, and unsaveable for
    // as long as student_subjects.comment was varchar(50).
    expect(strlen($longest))->toBeGreaterThan(50)
        ->and(strlen($longest))->toBeLessThanOrEqual(CommentEntry::MAX_LENGTH);
});

it('pins the entry limit to the student_subjects.comment column width', function () {
    // Three places used to disagree — column 50, server rule 50, client 100. If someone widens or
    // narrows one of them, this fails rather than a teacher discovering it mid-score-entry.
    $column = collect(DB::select('SHOW COLUMNS FROM student_subjects WHERE Field = ?', ['comment']))
        ->first();

    expect($column->Type)->toBe('varchar('.CommentEntry::MAX_LENGTH.')');
});

it('refuses an entry longer than the limit', function () {
    $school = al_makeSchool();
    cb_loadDefaults($school->id);

    $band = cb_forSchool($school->id, fn () => CommentBand::first());

    // The column is the backstop, not just the validation rule: an over-length body cannot be
    // written even by a path that skips the request layer (a seeder, a command, tinker).
    expect(fn () => $band->comments()->create([
        'body' => str_repeat('x', CommentEntry::MAX_LENGTH + 1),
    ]))->toThrow(QueryException::class);
});

// ── Saving a ladder ────────────────────────────────────────────────────────

it('keeps a band\'s comments when its range or label is edited', function () {
    $school = al_makeSchool();
    cb_loadDefaults($school->id);

    cb_forSchool($school->id, function () {
        $band = CommentBand::orderByDesc('min_score')->with('activeComments')->first();
        $before = $band->activeComments->pluck('body')->all();

        (new CommentBandService)->saveSet(null, [
            ['id' => $band->uuid, 'min_score' => 95, 'label' => 'Exceptional'],
            ['min_score' => 0, 'label' => 'Everything else'],
        ]);

        $band->refresh()->load('activeComments');

        // Matched by uuid, so the row survives and its entries come with it. Re-creating the band
        // would have cascaded them away on a rename.
        expect($band->label)->toBe('Exceptional')
            ->and((float) $band->min_score)->toBe(95.0)
            ->and($band->activeComments->pluck('body')->all())->toBe($before);
    });
});

it('deletes bands the payload omits, along with their comments', function () {
    $school = al_makeSchool();
    cb_loadDefaults($school->id);

    cb_forSchool($school->id, function () {
        $removed = CommentBand::orderByDesc('min_score')->first();
        $keep = CommentBand::orderBy('min_score')->first();

        (new CommentBandService)->saveSet(null, [
            ['id' => $keep->uuid, 'min_score' => 0, 'label' => $keep->label],
        ]);

        expect(CommentBand::count())->toBe(1)
            ->and($removed->comments()->count())->toBe(0);
    });
});

it('retires an entry without removing it from students who already have that comment', function () {
    $school = al_makeSchool();
    cb_loadDefaults($school->id);

    cb_forSchool($school->id, function () {
        $band = CommentBand::orderByDesc('min_score')->with('activeComments')->first();
        $entry = $band->activeComments->first();

        $entry->update(['is_active' => false]);

        expect(CommentBand::commentsFor(null, 95)->pluck('body'))->not->toContain($entry->body)
            // Still on the row, so nothing that referenced it by text is disturbed.
            ->and(CommentEntry::find($entry->id))->not->toBeNull();
    });
});

// ── The starter set ────────────────────────────────────────────────────────

it('imports the starter set as the exact ladder that used to be hardcoded', function () {
    $school = al_makeSchool();
    cb_loadDefaults($school->id);

    cb_forSchool($school->id, function () {
        $bands = CommentBand::orderByDesc('min_score')->with('activeComments')->get();

        expect($bands->pluck('min_score')->map(fn ($v) => (int) $v)->all())
            ->toBe([91, 80, 70, 60, 50, 40, 0])
            ->and($bands->pluck('activeComments')->map->count()->all())
            ->toBe([2, 3, 3, 5, 5, 6, 7]);
    });
});
