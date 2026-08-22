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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * U13 + U14 — THE DECIDED FEEDS, PROVEN OVER HTTP.
 *
 * WHAT WAS MISSING. Every credit-note and void-request READ in routes/endpoints/finance.php was
 * `/pending`. Once a checker approved or rejected one, the document left the approvals queue and
 * appeared on no LIST anywhere: it was in the ledger, it was in the balance, and the only way to see
 * it again was to already know which student it belonged to and open that student's statement. And
 * the one question maker-checker exists to answer — WHO APPROVED THIS — was unanswerable on every
 * surface in the application, because no resource emitted `decided_by` at all.
 *
 * THE GATE ASYMMETRY IS THE CLAIM MOST WORTH ATTACKING, so it is the first arm. `/pending` requires
 * the approve ability because it precedes an act; `/decided` takes the API group's `finance.access`
 * and nothing more, because it is a read of an act already taken. The arm proves both halves on ONE
 * user in ONE request pair: 200 on decided, 403 on pending. A test that only proved the 200 would
 * pass just as happily if the decided route had quietly inherited the approve middleware.
 *
 * THE ROWS ARE DRIVEN, NOT PLANTED. Every decided row in this file reached its status through the
 * real submit and approve/reject endpoints, so the `decided_by` these arms read is the one the
 * Action wrote under the Policy and the database CHECK — not a value a fixture asserted into place.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * A user in $school holding EXACTLY $permissions, via a dedicated role keyed to the permission set.
 *
 * The raw `model_has_roles` insert is the same choice ApprovalsQueueRendersEveryTypeTest makes and
 * for the same reason: grant-time duty separation refuses to CONSTRUCT a role holding both sides of
 * a Finance pair through the spatie API, and what is under test here is the ability set a request
 * carries, not the grant path.
 *
 * @param  list<string>  $permissions
 */
function dafUser(School $school, array $permissions): User
{
    $role = Role::firstOrCreate(['name' => 'daf_'.substr(md5(implode(',', $permissions)), 0, 12), 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->schools()->syncWithoutDetaching([$school->id]);
    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => User::class,
        'model_id' => $user->id,
        'school_id' => $school->id,
    ]);
    $user->flushSchoolAccessCache();
    // A runtime grant must invalidate spatie's cache, or the request-time PermissionMiddleware
    // resolves a stale role→permission map and 403s a genuinely granted ability.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** An active enrollment episode for a fresh student in $school; returns its uuid. */
function dafEnrollment(School $school): string
{
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]))->uuid;
}

/** Raise an invoice of $kind in $school as $maker; returns its uuid. */
function dafInvoice(School $school, User $maker, int $kobo, InvoiceKind $kind = InvoiceKind::Scheduled): string
{
    return test()->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => dafEnrollment($school),
            'kind' => $kind->value,
            'lines' => [['bank_account_id' => testBankAccountUuid($school->id), 'description' => 'Tuition', 'amount_minor' => $kobo]],
        ])->assertCreated()->json('id');
}

/**
 * The three seats this file needs, in one School: a maker holding both submit abilities, a checker
 * holding both approve and both reject abilities, and a READER holding `finance.access` alone.
 *
 * @return array{school: School, maker: User, checker: User, reader: User}
 */
function dafSeats(): array
{
    $school = School::factory()->create();

    return [
        'school' => $school,
        'maker' => dafUser($school, [
            'finance.access', 'finance.invoice.generate',
            'finance.credit-note.submit', 'finance.invoice.void-request.submit',
        ]),
        'checker' => dafUser($school, [
            'finance.access',
            'finance.credit-note.approve', 'finance.credit-note.reject',
            'finance.invoice.void-request.approve', 'finance.invoice.void-request.reject',
        ]),
        // The seat the decided feeds exist for: it may look at finance and may decide nothing.
        'reader' => dafUser($school, ['finance.access']),
    ];
}

/**
 * Drive one credit note and one void request to each terminal state in $school.
 *
 * Returns the wire ids so an arm can assert on a NAMED row rather than on "the first one", which is
 * what an ordering change would silently turn into a different assertion.
 *
 * @return array{approved_note: string, rejected_note: string, approved_void: string, rejected_void: string, pending_note: string}
 */
function dafDriveDecisions(array $seats): array
{
    $school = $seats['school'];
    $maker = $seats['maker'];
    $checker = $seats['checker'];

    $as = fn (User $u) => test()->actingAs($u)->withSession(['school_id' => $school->id]);

    // A credit note APPROVED — money moved. Against a SUPPLEMENTARY charge, so the invoice-kind
    // column has something to say that a term bill would not.
    $approvedNote = $as($maker)->postJson('/api/v1/finance/invoices/'.dafInvoice($school, $maker, 300000, InvoiceKind::Supplementary).'/credit-notes',
        ['amount_minor' => 30000, 'note' => 'sibling discount applied late'])->assertCreated()->json('id');
    $as($checker)->postJson("/api/v1/finance/credit-notes/{$approvedNote}/approve")->assertOk();

    // A credit note REJECTED — the reason is the thing that has to survive to the feed.
    $rejectedNote = $as($maker)->postJson('/api/v1/finance/invoices/'.dafInvoice($school, $maker, 400000).'/credit-notes',
        ['amount_minor' => 40000, 'note' => 'parent asked for a write-off'])->assertCreated()->json('id');
    $as($checker)->postJson("/api/v1/finance/credit-notes/{$rejectedNote}/reject",
        ['reason' => 'no evidence of the hardship claim'])->assertOk();

    // A void request APPROVED — the invoice is now void and its charge reversed.
    $approvedVoid = $as($maker)->postJson('/api/v1/finance/invoices/'.dafInvoice($school, $maker, 250000).'/void-requests',
        ['reason' => 'billed against the wrong episode'])->assertCreated()->json('id');
    $as($checker)->postJson("/api/v1/finance/void-requests/{$approvedVoid}/approve")->assertOk();

    // A void request REJECTED — the charge stands, with the checker's reason on the row.
    $rejectedVoid = $as($maker)->postJson('/api/v1/finance/invoices/'.dafInvoice($school, $maker, 260000).'/void-requests',
        ['reason' => 'duplicate'])->assertCreated()->json('id');
    $as($checker)->postJson("/api/v1/finance/void-requests/{$rejectedVoid}/reject",
        ['reason' => 'not a duplicate — two supplementary charges in one episode is legal'])->assertOk();

    // And one note left PENDING, so every arm below can assert the boundary rather than assume it.
    $pendingNote = $as($maker)->postJson('/api/v1/finance/invoices/'.dafInvoice($school, $maker, 500000).'/credit-notes',
        ['amount_minor' => 50000])->assertCreated()->json('id');

    return [
        'approved_note' => $approvedNote,
        'rejected_note' => $rejectedNote,
        'approved_void' => $approvedVoid,
        'rejected_void' => $rejectedVoid,
        'pending_note' => $pendingNote,
    ];
}

/** One row out of a `{"data": [...]}` feed, by its wire id. */
function dafRow(array $data, string $id): ?array
{
    foreach ($data as $row) {
        if ($row['id'] === $id) {
            return $row;
        }
    }

    return null;
}

it('serves the decided feeds to a finance.access reader who may decide nothing, and refuses that same reader the pending queues', function () {
    $seats = dafSeats();
    dafDriveDecisions($seats);

    $as = fn () => $this->actingAs($seats['reader'])->withSession(['school_id' => $seats['school']->id]);

    // THE POINT OF THE BRANCH, in one user. A reader holding `finance.access` and NOTHING else can
    // read what was decided…
    $as()->getJson('/api/v1/finance/credit-notes/decided')->assertOk();
    $as()->getJson('/api/v1/finance/void-requests/decided')->assertOk();

    // …and cannot see either worklist, because those precede an act they hold no authority over.
    // Both halves are asserted on the SAME seat: the 200s alone would pass just as happily if the
    // decided routes had silently inherited the approve middleware from the lines above them.
    $as()->getJson('/api/v1/finance/credit-notes/pending')->assertForbidden();
    $as()->getJson('/api/v1/finance/void-requests/pending')->assertForbidden();
});

it('returns exactly the approved and rejected documents, and never a pending one', function () {
    $seats = dafSeats();
    $ids = dafDriveDecisions($seats);

    $notes = $this->actingAs($seats['reader'])->withSession(['school_id' => $seats['school']->id])
        ->getJson('/api/v1/finance/credit-notes/decided')->assertOk()->json('data');

    expect(array_column($notes, 'id'))->toHaveCount(2)
        ->toContain($ids['approved_note'])
        ->toContain($ids['rejected_note'])
        // The pending note is live, submitted and in the same School — its absence is the filter
        // doing work, not an empty table.
        ->not->toContain($ids['pending_note']);

    expect(array_column($notes, 'status'))->each->toBeIn(['approved', 'rejected']);

    $voids = $this->actingAs($seats['reader'])->withSession(['school_id' => $seats['school']->id])
        ->getJson('/api/v1/finance/void-requests/decided')->assertOk()->json('data');

    expect(array_column($voids, 'id'))->toHaveCount(2)
        ->toContain($ids['approved_void'])
        ->toContain($ids['rejected_void']);
    expect(array_column($voids, 'status'))->each->toBeIn(['approved', 'rejected']);
});

it('names BOTH signatures on every decided row, and they are never the same person', function () {
    $seats = dafSeats();
    $ids = dafDriveDecisions($seats);
    $as = fn (string $url) => $this->actingAs($seats['reader'])->withSession(['school_id' => $seats['school']->id])
        ->getJson($url)->assertOk()->json('data');

    $rows = [
        ...$as('/api/v1/finance/credit-notes/decided'),
        ...$as('/api/v1/finance/void-requests/decided'),
    ];

    expect($rows)->toHaveCount(4);

    foreach ($rows as $row) {
        // "Who approved this" with one name on it answers nothing — the second signature IS the
        // control, so a row that cannot name it makes the control unreadable.
        expect($row['submitted_by_name'])->toBe($seats['maker']->name)
            ->and($row['decided_by_name'])->toBe($seats['checker']->name)
            ->and($row['decided_by_name'])->not->toBe($row['submitted_by_name'])
            ->and($row['decided_at'])->not->toBeNull();
    }

    // And the id behind the name is the checker's, read off the row the endpoint wrote. Ids, not
    // names, are what the constraint is expressed over.
    expect(DB::table('finance_credit_notes')->where('uuid', $ids['approved_note'])->value('decided_by'))
        ->toBe($seats['checker']->id)
        ->and(DB::table('finance_void_requests')->where('uuid', $ids['approved_void'])->value('decided_by'))
        ->toBe($seats['checker']->id);
});

it('names the invoice each document acts on WITH ITS KIND, and carries the reason only on a rejection', function () {
    $seats = dafSeats();
    $ids = dafDriveDecisions($seats);
    $as = fn (string $url) => $this->actingAs($seats['reader'])->withSession(['school_id' => $seats['school']->id])
        ->getJson($url)->assertOk()->json('data');

    $notes = $as('/api/v1/finance/credit-notes/decided');
    $approved = dafRow($notes, $ids['approved_note']);
    $rejected = dafRow($notes, $ids['rejected_note']);

    // U7 put `kind` on the wire because an episode can carry a term bill AND several supplementary
    // charges, so a number stopped identifying one document. A row naming the invoice must name it.
    expect($approved['invoice_kind'])->toBe('supplementary')
        // The invoice's presentation number, not its id. A factory School configures no
        // `invoice_number_prefix`, so Invoice::displayNumber() renders the bare padded sequence —
        // asserted against the row the note actually points at rather than against a literal, so a
        // School that DOES carry a prefix would not make this arm wrong.
        ->and($approved['invoice_display_number'])->toBe(
            Invoice::withoutGlobalScopes()
                ->find(DB::table('finance_credit_notes')
                    ->where('uuid', $ids['approved_note'])->value('invoice_id'))
                ->displayNumber()
        )
        ->and($approved['display_number'])->toStartWith('CN-')
        ->and($approved['amount'])->toBe(['amount_minor' => 30000, 'currency' => 'NGN'])
        // An APPROVED document has no rejection reason. A column that is populated on both is a
        // column nobody reads.
        ->and($approved['rejection_reason'])->toBeNull();

    expect($rejected['invoice_kind'])->toBe('scheduled')
        ->and($rejected['rejection_reason'])->toBe('no evidence of the hardship claim');

    $voids = $as('/api/v1/finance/void-requests/decided');

    expect(dafRow($voids, $ids['approved_void'])['invoice_kind'])->toBe('scheduled')
        ->and(dafRow($voids, $ids['approved_void'])['rejection_reason'])->toBeNull()
        ->and(dafRow($voids, $ids['rejected_void'])['rejection_reason'])
        ->toBe('not a duplicate — two supplementary charges in one episode is legal')
        // The reversal at stake — the invoice's full total, which is what a void undoes.
        ->and(dafRow($voids, $ids['rejected_void'])['amount'])->toBe(['amount_minor' => 260000, 'currency' => 'NGN']);
});

it('isolates by school — a reader never sees another school’s decisions', function () {
    $mine = dafSeats();
    $theirs = dafSeats();

    $myIds = dafDriveDecisions($mine);
    $theirIds = dafDriveDecisions($theirs);

    $as = fn (string $url) => $this->actingAs($mine['reader'])->withSession(['school_id' => $mine['school']->id])
        ->getJson($url)->assertOk()->json('data');

    // Asserted by ID, never by count or by label: two schools driven through the same helper produce
    // rows that look identical, so a leak would be invisible to anything but the identifier.
    $noteIds = array_column($as('/api/v1/finance/credit-notes/decided'), 'id');
    $voidIds = array_column($as('/api/v1/finance/void-requests/decided'), 'id');

    expect($noteIds)->toContain($myIds['approved_note'])
        ->not->toContain($theirIds['approved_note'])
        ->not->toContain($theirIds['rejected_note']);
    expect($voidIds)->toContain($myIds['approved_void'])
        ->not->toContain($theirIds['approved_void'])
        ->not->toContain($theirIds['rejected_void']);
});

it('orders by the decision, newest first', function () {
    $seats = dafSeats();
    $ids = dafDriveDecisions($seats);

    // The rejection is driven after the approval, so it is the newer decision.
    $notes = $this->actingAs($seats['reader'])->withSession(['school_id' => $seats['school']->id])
        ->getJson('/api/v1/finance/credit-notes/decided')->assertOk()->json('data');

    expect(array_column($notes, 'id'))->toBe([$ids['rejected_note'], $ids['approved_note']]);
});

it('leaves the pending queues’ payload exactly as it was — no checker key on a row with no checker', function () {
    $seats = dafSeats();
    dafDriveDecisions($seats);

    $pending = $this->actingAs($seats['checker'])->withSession(['school_id' => $seats['school']->id])
        ->getJson('/api/v1/finance/credit-notes/pending')->assertOk()->json('data');

    expect($pending)->toHaveCount(1);

    // `decided_by_name` is emitted through whenLoaded('decidedBy'), and the pending read does not
    // load it — so the key is ABSENT rather than null. That is the difference between "this document
    // has no checker" and "the checker is unknown", and it keeps the queue this branch did not touch
    // byte-identical to what it served before.
    expect($pending[0])->not->toHaveKey('decided_by_name')
        ->and($pending[0]['decided_at'])->toBeNull();
});
