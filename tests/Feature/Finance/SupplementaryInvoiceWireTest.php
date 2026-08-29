<?php

use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\Invoice;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * U7 — THE WIRE. `kind` on the generate request, and the modal's select behind it.
 *
 * WHAT WAS MISSING, AND IT WAS NEVER A BUG. #259 shipped the whole domain half: the enum, the
 * column, the re-keyed generated column and its unique index, the immutability trigger, and
 * GenerateInvoice's two `isEpisodeExclusive()` arms. What it did not ship was any way for a client
 * to ASK for a supplementary invoice — both controller call sites passed `InvoiceKind::Scheduled`
 * as a literal and said so in a comment. A bursar raising a one-off charge against an episode that
 * already carried its term bill therefore met "This enrollment already has an active TERM invoice.
 * Void it before billing the term again.", which is the CORRECT refusal for the only invoice the
 * modal could request.
 *
 * WHY THESE ARMS AND NOT FEWER. Arms a/b/d/e are about the WIRE and go over HTTP, because the wire
 * is the thing that did not exist — a test driving the Action proves #259 again and proves nothing
 * about this branch. Arm c is about the DATABASE and deliberately bypasses the Action: the
 * one-per-episode invariant's authority is a unique index over a STORED generated column, and an
 * arm that only drives GenerateInvoice cannot tell the index from the pre-check. Both would be
 * green with the index dropped.
 *
 * The 422 arms assert the MESSAGE, not the status. Immutability, a negative total, a missing
 * enrollment and a bad discount policy all answer 422 on these routes; only the sentence
 * discriminates.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: Student, 3: StudentCurriculum} */
function swSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);

    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'sw_bursar', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.invoice.generate'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.invoice.generate']);
    $admin->assignRole('sw_bursar');
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $student = Student::factory()->create(['school_id' => $school->id]);
    $enrollment = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return [$school, $admin, $student, $enrollment];
}

/**
 * THE ROUTE THE MODAL POSTS TO — POST /v1/finance/students/{student:uuid}/invoices. `$payload` is
 * merged over the lines so an arm can omit `kind` entirely (the absent case) or plant a value
 * outside the enum.
 */
function swPost($test, School $school, User $admin, Student $student, array $payload = [], string $what = 'Tuition', int $minor = 150000)
{
    return $test->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/invoices", array_merge([
            'lines' => [['bank_account_id' => testBankAccountUuid(), 'description' => $what, 'amount_minor' => $minor, 'kind' => 'charge']],
        ], $payload));
}

/** The other reachable generate route — POST /v1/finance/invoices, the enrollment-id harness POST. */
function swPostByEnrollment($test, School $school, User $admin, StudentCurriculum $enrollment, array $payload = [])
{
    return $test->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', array_merge([
            'enrollment_id' => $enrollment->uuid,
            'lines' => [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 150000, 'kind' => 'charge']],
        ], $payload));
}

/**
 * A raw INSERT into finance_invoices, bypassing GenerateInvoice, its pre-check and its
 * duplicate-key translation entirely. This is what makes arm c a test of the INDEX.
 */
function swRawInsert(Invoice $like, array $overrides = []): void
{
    DB::table('finance_invoices')->insert(array_merge([
        'uuid' => (string) Str::uuid(),
        'school_id' => $like->school_id,
        'student_id' => $like->student_id,
        'student_curriculum_id' => $like->student_curriculum_id,
        'number' => $like->number + random_int(1000, 9999),
        'status' => 'issued',
        'kind' => 'scheduled',
        'billed_to_name' => $like->billed_to_name,
        'academic_context' => $like->academic_context,
        'total_minor' => 5000,
        'total_currency' => 'NGN',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** The driver code of the QueryException a closure raises, or null if it raised none. */
function swDriverCode(Closure $write): ?int
{
    try {
        $write();
    } catch (QueryException $e) {
        return (int) $e->errorInfo[1];
    }

    return null;
}

/**
 * The invoice a 201 body names, read back from the DATABASE.
 *
 * This used to say "— InvoiceResource does not carry `kind`", which was true when it was written and
 * is not any more: U7 put `kind` on the resource, and InvoiceKindOnReadPathsTest asserts the 201
 * carries it. The database read stays, deliberately. These arms are about what GenerateInvoice
 * WROTE, and reading the row is the only way to assert that independently of the serialiser — an
 * arm that trusted the response body would go green on a resource that echoed the requested `kind`
 * back without the write having happened.
 */
function swCreated($response): Invoice
{
    return Invoice::withoutGlobalScopes()->where('uuid', $response->json('id'))->firstOrFail();
}

// ── a — THE REPORTED CASE ─────────────────────────────────────────────────────

it('a — an episode with an ACTIVE TERM INVOICE accepts a SUPPLEMENTARY invoice over the wire', function () {
    // This is the bursar's screen, in order: the term bill exists, and then a one-off charge is
    // raised against the same episode. Before this branch the second request could not be
    // expressed — the modal posted `{lines}` and the controller named Scheduled — so it arrived as
    // a term bill and was refused. Both requests here go over HTTP for that reason.
    [$school, $admin, $student, $enrollment] = swSetup();

    swPost($this, $school, $admin, $student, ['kind' => 'scheduled'])->assertCreated();

    $response = swPost($this, $school, $admin, $student, ['kind' => 'supplementary'], 'Damaged locker door', 12345)
        ->assertCreated();

    $supplementary = swCreated($response);

    expect($supplementary->kind)->toBe(InvoiceKind::Supplementary)
        // On the SAME episode and LIVE — otherwise the arm would be satisfied by an invoice that
        // never collided with anything in the first place.
        ->and($supplementary->student_curriculum_id)->toBe($enrollment->id)
        ->and($supplementary->status->value)->toBe('issued')
        ->and(Invoice::withoutGlobalScopes()->where('student_curriculum_id', $enrollment->id)->count())->toBe(2);
});

it('a2 — the harness route (POST /v1/finance/invoices) carries the same choice', function () {
    // The two generate routes share one FormRequest, so this is true by construction rather than by
    // a second implementation. Pinned anyway: a subclass unsetting the rule, or a controller
    // reverting to a literal, would be invisible on the modal's route alone.
    [$school, $admin, , $enrollment] = swSetup();

    swPostByEnrollment($this, $school, $admin, $enrollment, ['kind' => 'scheduled'])->assertCreated();

    $response = swPostByEnrollment($this, $school, $admin, $enrollment, ['kind' => 'supplementary'])
        ->assertCreated();

    expect(swCreated($response)->kind)->toBe(InvoiceKind::Supplementary);
});

// ── b — THE GUARD DID NOT MOVE ────────────────────────────────────────────────

it('b — the same episode STILL refuses a second SCHEDULED invoice, with the same sentence', function () {
    // The whole risk of this branch is that widening the wire quietly widened the guard. It did not:
    // hasActiveScheduledInvoiceForEnrollment is untouched, and a supplementary invoice sitting on
    // the episode does not buy the term bill a second slot.
    [$school, $admin, $student] = swSetup();

    swPost($this, $school, $admin, $student, ['kind' => 'scheduled'])->assertCreated();
    swPost($this, $school, $admin, $student, ['kind' => 'supplementary'], 'Damaged locker door', 12345)->assertCreated();

    swPost($this, $school, $admin, $student, ['kind' => 'scheduled'])
        ->assertStatus(422)
        // THE MESSAGE, NOT THE STATUS. 422 on this route is also how a bad discount policy, a
        // negative total and a missing enrollment answer; only the sentence says WHICH refusal
        // fired, and this sentence is the one the modal's amber banner is kept in step with.
        ->assertJsonPath('message', 'This enrollment already has an active TERM invoice. Void it before billing the term again.');

    expect(Invoice::withoutGlobalScopes()->count())->toBe(2);
});

// ── b2 — WHICH LAYER ANSWERED ─────────────────────────────────────────────────

it('b2 — the second SCHEDULED post is refused BY THE PRE-CHECK, not by the index it backstops', function () {
    // ARM (b) ABOVE DOES NOT PIN THIS, AND SAYING SO IS THE POINT OF THIS ARM. Delete
    // GenerateInvoice's `if ($kind->isEpisodeExclusive()) { $this->assertNoActiveInvoice(…); }` and
    // arm (b) STILL PASSES: the unique index refuses the second term bill anyway, and the 1062
    // translation further down throws the identical sentence, so the message assertion is satisfied
    // by the exception path. The whole of tests/Feature/Finance comes back byte-identical with that
    // block gone. A proof that survives deleting the thing it names is not proving that thing.
    //
    // WHY THIS IS AN INSTRUMENTATION ASSERTION AND NOT A BEHAVIOURAL ONE. Two obvious behavioural
    // discriminators were tried and neither works:
    //
    //   - "the refusal consumes no invoice number". Sequences::next runs AFTER the pre-check, so on
    //     the pre-check path no number is drawn — but Sequences::next opens a NESTED transaction, so
    //     when the Action's transaction rolls back the increment rolls back with it. The counter
    //     ends at the same value either way and cannot tell the paths apart.
    //   - "no INSERT was attempted". DB::listen fires from Connection::logQuery, which runs only
    //     after a statement SUCCEEDS; a failed INSERT is never logged. Absence of an insert is
    //     therefore true on both paths and is vacuous.
    //
    // What IS observable is the pre-check's own SELECT — the kind-filtered existence read that
    // InvoiceReadModel::activeScheduledInvoiceIdForEnrollment issues. It succeeds, so it is logged;
    // it exists only on the pre-check path. Asserting it is present is asserting that the pre-check
    // ran, which is the claim.
    [$school, $admin, $student] = swSetup();

    swPost($this, $school, $admin, $student, ['kind' => 'scheduled'])->assertCreated();

    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    swPost($this, $school, $admin, $student, ['kind' => 'scheduled'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This enrollment already has an active TERM invoice. Void it before billing the term again.');

    $preCheckReads = array_values(array_filter(
        $statements,
        fn (string $sql) => str_contains($sql, 'finance_invoices')
            && str_starts_with(ltrim(strtolower($sql)), 'select')
            && str_contains($sql, '`kind`')
            && str_contains($sql, '`student_curriculum_id`'),
    ));

    // AT LEAST ONE, not exactly one: the assertion is "the pre-check ran", and pinning a count
    // would red on an unrelated extra read of the same table and prove nothing more.
    expect($preCheckReads)->not->toBeEmpty();
});

// ── f — AN EMPTY KIND IS NOT AN ABSENT KIND ──────────────────────────────────

it('f — an explicit null or empty-string `kind` is refused, not defaulted to scheduled', function () {
    // THE PROPERTY `sometimes` BUYS, WHICH NOTHING ELSE IN THIS FILE COVERS. Arm (d) proves an
    // ABSENT key defaults to scheduled; arm (e) proves a GARBLED value is a field error. Neither
    // touches the case between them — a key that is present and EMPTY.
    //
    // Under `nullable` both payloads below would VALIDATE and return 201 with a term bill:
    // `nullable` short-circuits the remaining rules on a null value, so Rule::enum never sees it and
    // invoiceKind() takes the absent-means-scheduled branch. ConvertEmptyStringsToNull turns `""`
    // into null before any rule runs, so the empty-string case is the same case — and `""` is what
    // an untouched <select> posts. A client that rendered the control, never touched it, and posted
    // would be billed for the term while believing it had chosen.
    //
    // THE WATCHED RED FOR THIS ARM IS `'sometimes'` → `'nullable'` on the rule. Nothing else in the
    // suite distinguishes those two rules — the file is 6/6 green under both without this arm.
    [$school, $admin, $student] = swSetup();

    swPost($this, $school, $admin, $student, ['kind' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['kind']);

    swPost($this, $school, $admin, $student, ['kind' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['kind']);

    // Nothing written by either — a 422 that still leaves a row is the other half of the failure.
    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(0);
});

// ── c — THE DATABASE, NOT THE APP ─────────────────────────────────────────────

it('c — the generated-column unique index refuses a second SCHEDULED and permits a second SUPPLEMENTARY', function () {
    // NEITHER WRITE GOES THROUGH GenerateInvoice. The Action's pre-check would refuse the first
    // before MySQL saw it, and its 1062 translation would hide which layer answered. What is under
    // test is `active_enrollment_key = IF(status = 'issued' AND kind = 'scheduled', …)` and
    // UNIQUE(school_id, active_enrollment_key), installed by 2026_08_18_100000. An arm driving the
    // Action passes with that index dropped.
    [$school, $admin, $student, $enrollment] = swSetup();

    $scheduled = swCreated(swPost($this, $school, $admin, $student, ['kind' => 'scheduled'])->assertCreated());

    // The refusal: a SECOND issued scheduled invoice on the same episode computes the same
    // non-NULL key and collides. 1062 is MySQL's duplicate-entry code.
    expect(swDriverCode(fn () => swRawInsert($scheduled, ['kind' => 'scheduled'])))->toBe(1062);

    // The permission: a supplementary invoice computes a NULL key, and NULLs do not collide in a
    // MySQL unique index — so any number coexist with the one term bill. Two of them, because one
    // proves only that it does not collide with the SCHEDULED invoice; the second proves
    // supplementary invoices do not collide with EACH OTHER either, which is the property the
    // feature actually needs.
    expect(swDriverCode(fn () => swRawInsert($scheduled, ['kind' => 'supplementary'])))->toBeNull()
        ->and(swDriverCode(fn () => swRawInsert($scheduled, ['kind' => 'supplementary'])))->toBeNull()
        ->and(Invoice::withoutGlobalScopes()->where('student_curriculum_id', $enrollment->id)->count())->toBe(3);
});

// ── d — ABSENT MEANS SCHEDULED ────────────────────────────────────────────────

it('d — `kind` absent from the payload produces a SCHEDULED invoice', function () {
    // THE WIRE-COMPATIBILITY ARM. Every caller written before this branch — the harness POST, the
    // bulk run, the seeder, the rest of this suite — sends no `kind`. If `sometimes` were ever
    // changed to `required`, or invoiceKind() stopped defaulting, all of them would break at once
    // and this is the arm that says so in one sentence rather than forty.
    [$school, $admin, $student] = swSetup();

    $response = swPost($this, $school, $admin, $student)->assertCreated();

    expect(swCreated($response)->kind)->toBe(InvoiceKind::Scheduled);

    // And the guard it implies is live: a second no-kind post is refused as a term bill.
    swPost($this, $school, $admin, $student)
        ->assertStatus(422)
        ->assertJsonPath('message', 'This enrollment already has an active TERM invoice. Void it before billing the term again.');
});

// ── e — AN INVALID KIND IS A FIELD ERROR, NOT A FALLBACK ──────────────────────

it('e — an invalid `kind` is a 422 field error and writes nothing', function () {
    // THE FAILURE MODE THIS ARM EXISTS FOR IS SILENCE, NOT AN ERROR. A `nullable` rule, or an
    // invoiceKind() built on tryFrom() over raw input, would let a typo through to the default and
    // raise a TERM bill for a request that asked for a supplementary charge — the wrong document,
    // created successfully, with a 201 and no complaint anywhere.
    [$school, $admin, $student] = swSetup();

    swPost($this, $school, $admin, $student, ['kind' => 'supplemenatry'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['kind']);

    // A refusal that 422s and still leaves a row behind is the other half of the failure, and the
    // status alone cannot see it. Both tables, because they are written in one transaction.
    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(0);

    // Not only a garbled enum value: the LINE kinds are a different vocabulary on the same wire and
    // must not be accepted here. 'charge' is a valid InvoiceLineKind and not an InvoiceKind.
    swPost($this, $school, $admin, $student, ['kind' => 'charge'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['kind']);

    expect(DB::table('finance_invoices')->count())->toBe(0);
});
