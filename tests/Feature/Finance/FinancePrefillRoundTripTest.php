<?php

use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Models\BankAccount;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * U8 commit 2 — `GET /v1/finance/fee-schedules/prefill` and `POST /v1/finance/invoices` are ONE CONTRACT,
 * and this file is the only thing that says so.
 *
 * The route comment at routes/endpoints/finance.php:88 states the relationship in prose — prefill
 * "resolves the ACTIVE schedule's items into prefilled charge lines for the bursar's generate form" — so
 * the `lines` array prefill returns is a REQUEST BODY for the generate endpoint. Nothing enforced that.
 * The two shapes lived in different files, neither referenced the other in code, and prefill went on
 * emitting `fee_item_id` as the INTEGER id after U8 commit 1 made that endpoint refuse integers. Every
 * test in the suite hand-built its generate payload, so every one of them was green while the one payload
 * a real bursar screen would actually send was a 422.
 *
 * THE ARM BELOW POSTS WHAT PREFILL RETURNED, UNMODIFIED. That is the whole point: a test that rebuilds
 * the payload — even faithfully, even from the same fixture — proves the wire accepts a body someone
 * wrote by hand, which is exactly the thing that was already true and already useless. `$response->json('lines')`
 * goes into the POST with no key added, removed or rewritten, so a future field added to prefill is
 * carried into the generate request by this test whether or not anyone remembers to update it.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * A School with an ACTIVE fee schedule of `$descriptions`, an enrollment, and an admin holding
 * `finance.access` (the group middleware, which prefill needs) + `finance.invoice.generate`.
 *
 * @return array{0: School, 1: User, 2: StudentCurriculum, 3: Term, 4: ClassLevel, 5: Collection}
 */
function prtSetup(array $descriptions = ['Tuition', 'Transport']): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'prt_admin', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.invoice.generate'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.invoice.generate']);
    $admin->assignRole('prt_admin');
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true]);
    $term = Term::create([
        'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
        'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
    ]);
    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);

    // A SECOND account, so the two items point at DIFFERENT ones. With both on the school's single
    // account, "prefill emitted the item's own destination" and "prefill emitted any account of this
    // School" are indistinguishable, and the round-trip arm's destination assertion could not fail on
    // its own axis. Same reasoning as the non-discountable second item one comment down.
    $secondAccount = BankAccount::withoutGlobalScopes()->firstOrCreate(
        ['school_id' => $school->id, 'account_number' => 'PRT-SECOND-'.$school->id],
        ['label' => 'Second account', 'bank_name' => 'Test Bank'],
    );

    // Two items, and the SECOND is non-discountable — so the fixture distinguishes a resolution that
    // returns the right row from one that returns any row, and so `is_discountable` is not the same
    // value on every line (a flag that is `true` everywhere cannot show that it travelled).
    $specs = [];
    foreach (array_values($descriptions) as $i => $description) {
        $specs[] = [
            'bank_account_id' => $i === 0 ? testBankAccountUuid($school->id) : (string) $secondAccount->uuid,
            'description' => $description,
            'amount_minor' => 100000 * ($i + 1),
            'currency' => 'NGN',
            'is_discountable' => $i === 0,
            'is_mandatory' => $i === 0,
        ];
    }

    $schedule = ActiveSchool::runFor($school->id, fn () => app(CreateFeeSchedule::class)->handle($term->id, $level->id, 'v1', $specs));

    // CreateFeeSchedule always authors a DRAFT and a draft does not prefill; the publish path is the
    // fee-schedule-change approval, which is not what this file tests. Raw status write, the way the rest
    // of the suite moves a lifecycle it is not exercising.
    DB::table('finance_fee_schedules')->where('id', $schedule->id)->update(['status' => 'active']);

    $student = Student::factory()->create(['school_id' => $school->id]);
    $enrollment = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return [$school, $admin, $enrollment, $term, $level, $schedule->items];
}

it('round-trips prefill’s lines into the generate endpoint VERBATIM, and stores the right fee item ids', function () {
    [$school, $admin, $enrollment, $term, $level, $items] = prtSetup();

    $this->actingAs($admin)->withSession(['school_id' => $school->id]);

    $prefill = $this->getJson("/api/v1/finance/fee-schedules/prefill?term_id={$term->id}&class_level_id={$level->id}")
        ->assertOk();

    $lines = $prefill->json('lines');

    // A precondition, not decoration: if prefill returned nothing, the POST below would fail on
    // `lines.min:1` and the arm would read as a contract failure when the fixture is what broke.
    expect($lines)->toHaveCount(2);

    // VERBATIM. No map, no filter, no unset — `$lines` is passed exactly as received.
    $this->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enrollment->uuid,
        'lines' => $lines,
    ])->assertCreated();

    // A 201 IS NOT ENOUGH. `fee_item_id` is nullable on finance_invoice_lines with no foreign key, so a
    // resolution that silently produced NULL — or resolved to the wrong item — writes a perfectly valid
    // invoice with the provenance gone. The assertion is against the ids of the items PREFILL DESCRIBED,
    // matched by description so it does not depend on row order.
    $expected = $items->keyBy('description')->map(fn ($item) => (int) $item->id);

    $stored = DB::table('finance_invoice_lines')->get()->mapWithKeys(
        fn ($line) => [$line->description => $line->fee_item_id === null ? null : (int) $line->fee_item_id],
    );

    expect($stored->all())->toBe($expected->all(),
        'The invoice stored a different fee_item_id than the item prefill named — or NULL, which is what '
        .'a silently dropped provenance looks like. finance_invoice_lines.fee_item_id carries no foreign '
        .'key by policy, so nothing but this assertion notices.');

    // AND THE DESTINATION PREFILL NAMED, for the same reason one line up and one added in S11 commit 2:
    // a 201 now proves only that SOME account was named, because the destination guard would have
    // refused a null one. What it does not prove is that the account is the one the fee item carries —
    // a prefill emitting any valid uuid of this School would still be accepted and would silently
    // re-point the money. Matched by description so it does not depend on row order.
    $expectedAccounts = $items->keyBy('description')->map(fn ($item) => (int) $item->bank_account_id);

    $storedAccounts = DB::table('finance_invoice_lines')->get()->mapWithKeys(
        fn ($line) => [$line->description => $line->bank_account_id === null ? null : (int) $line->bank_account_id],
    );

    expect($storedAccounts->all())->toBe($expectedAccounts->all(),
        'The invoice stored a different destination than the fee item prefill named. The destination '
        .'guard only refuses NULL; naming the WRONG account is a 201 and this assertion is what sees it.');
});

it('carries prefill’s ignored form flags through the generate endpoint without refusing them', function () {
    // `is_mandatory` and `is_discountable` are emitted by prefill and validated by NOTHING on
    // GenerateInvoiceRequest. Laravel does not refuse unknown keys, and `lineSpecs()` reads the fields it
    // wants by name, so both are IGNORED rather than refused — which is what makes the verbatim round trip
    // above possible at all. This arm states that outcome directly, so a future `prohibited` rule or a
    // strict-body change fails HERE, naming the flags, instead of failing the round trip for a reason its
    // assertion message does not mention.
    //
    // It also pins that `is_discountable` does not travel INTO the line: GenerateInvoice re-resolves it
    // from the fee item (:281, :301) precisely so a client cannot move the percentage base by echoing
    // this value back. There is no `is_discountable` column on finance_invoice_lines to check, so the
    // check is that the request carrying it is accepted and the stored row is unaffected by it.
    [$school, $admin, $enrollment, $term, $level] = prtSetup();

    $this->actingAs($admin)->withSession(['school_id' => $school->id]);

    $lines = $this->getJson("/api/v1/finance/fee-schedules/prefill?term_id={$term->id}&class_level_id={$level->id}")
        ->assertOk()->json('lines');

    expect(array_keys($lines[0]))->toBe(
        ['description', 'amount_minor', 'currency', 'kind', 'fee_item_id', 'bank_account_id', 'is_mandatory', 'is_discountable'],
        'prefill gained or lost a line key. Every key here is posted verbatim to the generate endpoint by '
        .'the arm above, so a new one is a new wire field on POST /v1/finance/invoices whether or not '
        .'GenerateInvoiceRequest was told about it.');

    // `bank_account_id` IS NOT AN IGNORED FLAG, unlike the two below, and it joined this list in S11
    // commit 2 rather than being an incidental addition: `finance_invoice_lines_destination_guard`
    // refuses a charge line without one, so a prefill payload missing this key posts back as a 422 on
    // every line. Its VALUE is asserted in the round-trip arm above, against the item it came from.

    // The two flags differ across the fixture's lines, so this is a real reading of both, not of a
    // constant. Line 0 is mandatory + discountable, line 1 is neither.
    expect([$lines[0]['is_mandatory'], $lines[0]['is_discountable']])->toBe([true, true])
        ->and([$lines[1]['is_mandatory'], $lines[1]['is_discountable']])->toBe([false, false]);

    $this->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enrollment->uuid,
        'lines' => $lines,
    ])->assertCreated();

    expect(DB::table('finance_invoice_lines')->count())->toBe(2);
});

it('emits the SAME fee item uuid twice in one prefill response, under two names', function () {
    // OBSERVED, NOT ENDORSED. `schedule.items[i].id` (FeeScheduleResource:93) and `lines[i].fee_item_id`
    // are the same string: the resource half serialises each item's uuid as its `id`, and since U8
    // commit 2 the lines half serialises the same uuid under the name the generate endpoint expects.
    // Two names for one value in one payload is a thing a reader should know before deciding it is
    // duplication to remove — the two halves address different consumers (a display list and a request
    // body) and collapsing them is a payload decision, not a cleanup. This arm exists so that decision is
    // made deliberately rather than discovered.
    [$school, $admin, , $term, $level] = prtSetup();

    $response = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/fee-schedules/prefill?term_id={$term->id}&class_level_id={$level->id}")
        ->assertOk();

    $scheduleIds = collect($response->json('schedule.items'))->pluck('id')->all();
    $lineIds = collect($response->json('lines'))->pluck('fee_item_id')->all();

    expect($lineIds)->toBe($scheduleIds)
        ->and($lineIds[0])->toBeString();
});
