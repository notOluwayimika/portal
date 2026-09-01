<?php

/*
 * THE ACCOUNTS MONEY LANDS IN, AND THE ONE IT SETTLES TO, ARE AUDITED ACTS.
 *
 * Five acts. Each writes an ACTOR on the row and an ENTRY in activity_log, and the two are not two
 * spellings of one fact:
 *
 *   the row    who is responsible for the account / the settlement destination RIGHT NOW
 *   the log    what it has EVER been, in sequence, with the before and after of what moved
 *
 * The row can only hold the current answer; the log is the only place the previous one survives.
 * Every arm below asserts BOTH halves for its act, because a change that records one and not the
 * other leaves a question with no answer and looks complete from either side alone.
 *
 * THE LAST TWO ARMS ARE THE POINT OF THE WHOLE CHANGE. A trail nobody can read is not a control,
 * and before this branch the audit-only seat could not read this one — not "could not see the row",
 * could not reach the endpoint. `internal_auditor` holds `activity_log.view` and
 * `activity_log.export`; `/api/activity-logs` sat inside a route group gated on
 * `academic_data.view`, which the seat does not hold, so it was refused at the door while a TEACHER
 * walked in (measured against tests/fixtures/route-access-map.json before the change). And even
 * past the door, `ActivityLogQueryService::baseQuery` restricts a viewer without
 * `activity_log.view_all` to rows they caused THEMSELVES — which for an auditor is the empty set by
 * construction. Both had to change; the arms named `the internal_auditor seat` are what say so.
 *
 * AND THE KNOWN NEGATIVE. Every arm here is a positive: it plants an act and demands a record. A
 * suite of positives cannot tell a working recorder from one that writes a row for everything,
 * including acts that did not happen. `does NOT write an entry for an idempotent deactivation` is
 * the negative, and it is the arm to read first if this file goes red as a block.
 */

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\SetSettlementBankAccount;
use App\Finance\Models\BankAccount;
use App\Finance\Models\SchoolFinanceSettings;
use App\Models\Activity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\ActivityLog\ActivitySeverityService;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * A seat in $school holding EXACTLY $permissions, on a role of its own.
 *
 * A role per permission-set, never a shared one: `RbacSeeder::grantsMap()` already grants `admin`
 * things these arms need withheld, and a seat built on a shared role holds whatever the seeder
 * decided rather than what the arm asked for.
 *
 * @param  list<string>  $permissions
 */
function amdSeat(School $school, array $permissions): User
{
    $roleName = 'amd_'.substr(md5(implode(',', $permissions)), 0, 10);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $roleName);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** The real seat, as the seeder grants it — not a hand-built approximation of it. */
function amdInternalAuditor(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'internal_auditor');
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/**
 * What a seat can do IN a school.
 *
 * Asked under ActiveSchool::runFor, because `roles` is team-scoped: `User::grantSchoolAccess()`
 * sets the team for the write and restores what it found, so a bare `can()` afterwards resolves
 * against team NULL and answers `false` for every ability the seat really holds. Getting that
 * wrong reads as "the grant did not land".
 *
 * @param  list<string>  $abilities
 * @return array<string, bool>
 */
function amdAbilities(User $user, School $school, array $abilities): array
{
    return ActiveSchool::runFor($school->id, function () use ($user, $abilities): array {
        $fresh = $user->fresh();
        $fresh->unsetRelation('roles');

        $out = [];
        foreach ($abilities as $ability) {
            $out[$ability] = $fresh->can($ability);
        }

        return $out;
    });
}

function amdAccount(School $school, array $attributes = []): BankAccount
{
    return ActiveSchool::runFor($school->id, fn () => BankAccount::create(array_merge([
        'school_id' => $school->id,
        'label' => 'Zenith — Fees',
        'bank_name' => 'Zenith Bank',
        'account_number' => '10'.random_int(10000000, 99999999),
    ], $attributes)));
}

/** The activity rows for one event, newest first. Read raw so no scope hides the answer. */
function amdRows(string $event): Collection
{
    return Activity::query()->withoutGlobalScopes()
        ->where('log_name', 'finance')->where('event', $event)
        ->orderByDesc('id')->get();
}

// ── The four bank-account acts ──────────────────────────────────────────────────────────────────

it('records the actor and an entry when a bank account is CREATED', function () {
    $school = School::factory()->create();
    $manager = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);

    $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts', [
            'label' => 'Zenith — Fees',
            'bank_name' => 'Zenith Bank',
            'account_number' => '1234567890',
        ])->assertCreated();

    $account = BankAccount::withoutGlobalScopes()->latest('id')->firstOrFail();

    expect($account->created_by_user_id)->toBe($manager->id)
        ->and($account->updated_by_user_id)->toBe($manager->id);

    $row = amdRows('bank_account_created')->first();

    expect($row)->not->toBeNull()
        ->and($row->causer_id)->toBe($manager->id)
        ->and($row->subject_id)->toBe($account->id)
        ->and((int) $row->school_id)->toBe($school->id)
        ->and($row->properties['bank_account_uuid'])->toBe($account->uuid)
        // The account NUMBER must not be copied into a table read by every activity_log.view
        // holder. The uuid and label identify it; the number is one join away for the seat that
        // may see it.
        ->and(json_encode($row->properties))->not->toContain('1234567890');
});

it('records the actor and an entry when a bank account is UPDATED', function () {
    $school = School::factory()->create();
    $manager = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school, ['label' => 'Old label']);

    $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->patchJson('/api/v1/finance/bank-accounts/'.$account->uuid, ['label' => 'New label'])
        ->assertOk();

    expect($account->fresh()->updated_by_user_id)->toBe($manager->id);

    $row = amdRows('bank_account_updated')->first();

    expect($row)->not->toBeNull()
        ->and($row->causer_id)->toBe($manager->id)
        // from → to, not just "something changed". A trail that says an edit happened without
        // saying what it was cannot answer the question it exists for.
        ->and($row->properties['from']['label'])->toBe('Old label')
        ->and($row->properties['to']['label'])->toBe('New label');
});

it('records the actor and an entry when a bank account is DEACTIVATED', function () {
    $school = School::factory()->create();
    $manager = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school);

    $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts/'.$account->uuid.'/deactivate')
        ->assertOk();

    $fresh = $account->fresh();

    expect($fresh->deactivated_at)->not->toBeNull()
        ->and($fresh->deactivated_by_user_id)->toBe($manager->id)
        ->and($fresh->updated_by_user_id)->toBe($manager->id);

    $row = amdRows('bank_account_deactivated')->first();

    expect($row)->not->toBeNull()
        ->and($row->causer_id)->toBe($manager->id)
        ->and($row->properties['was_settlement_account'])->toBeFalse();
});

it('flags a deactivation that retires the account settlement still points at', function () {
    // Nothing REFUSES this — a two-step swap is legitimate — but SettlementBankAccount::forSchool()
    // keeps returning the id, so gateway money carries on arriving in a retired account. The
    // property is what makes that visible at the moment it happens. The guard is ticketed:
    // docs/handoff/tickets/deactivating-the-settlement-account-is-not-refused.md
    $school = School::factory()->create();
    $manager = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school);

    (new SetSettlementBankAccount)->handle($school->id, $account->uuid, $manager);

    $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts/'.$account->uuid.'/deactivate')
        ->assertOk();

    expect(amdRows('bank_account_deactivated')->first()->properties['was_settlement_account'])
        ->toBeTrue();
});

it('records the actor and an entry when a bank account is REACTIVATED', function () {
    $school = School::factory()->create();
    $retirer = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school);

    $this->actingAs($retirer)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts/'.$account->uuid.'/deactivate')->assertOk();

    $this->actingAs($retirer)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts/'.$account->uuid.'/reactivate')->assertOk();

    $fresh = $account->fresh();

    expect($fresh->deactivated_at)->toBeNull()
        // The pair describes the CURRENT retirement, and there is none.
        ->and($fresh->deactivated_by_user_id)->toBeNull()
        ->and($fresh->updated_by_user_id)->toBe($retirer->id);

    // …and who retired it survives ONLY in the log, which is the whole reason both are written.
    expect(amdRows('bank_account_deactivated')->first()->causer_id)->toBe($retirer->id)
        ->and(amdRows('bank_account_reactivated')->first()->causer_id)->toBe($retirer->id);
});

it('does NOT write an entry for an idempotent deactivation — the known negative', function () {
    // Without this arm, every assertion in this file is satisfied by a recorder that writes a row
    // for anything, including acts that did not happen. It is also the guard on the timestamp:
    // "when did we stop using this" has one answer and a second click must not rewrite it.
    $school = School::factory()->create();
    $manager = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school);

    $call = fn () => $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts/'.$account->uuid.'/deactivate')->assertOk();

    $call();
    $firstAt = $account->fresh()->deactivated_at;

    $call();

    expect(amdRows('bank_account_deactivated'))->toHaveCount(1)
        ->and($account->fresh()->deactivated_at->eq($firstAt))->toBeTrue();
});

// ── The settlement selection ────────────────────────────────────────────────────────────────────

it('records the actor, the timestamp and an entry when the SETTLEMENT account is chosen', function () {
    $school = School::factory()->create();
    $actor = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school);

    $result = (new SetSettlementBankAccount)->handle($school->id, $account->uuid, $actor);

    expect($result)->toBe(['from' => null, 'to' => $account->id]);

    $settings = SchoolFinanceSettings::withoutGlobalScopes()
        ->where('school_id', $school->id)->firstOrFail();

    expect((int) $settings->settlement_bank_account_id)->toBe($account->id)
        ->and((int) $settings->settlement_bank_account_set_by_user_id)->toBe($actor->id)
        ->and($settings->settlement_bank_account_set_at)->not->toBeNull();

    $row = amdRows('settlement_account_changed')->first();

    expect($row)->not->toBeNull()
        ->and($row->causer_id)->toBe($actor->id)
        // FIRST configuration: from is null, and that is a distinct state from a re-point.
        ->and($row->properties['from']['bank_account_id'])->toBeNull()
        ->and($row->properties['to']['bank_account_id'])->toBe($account->id);
});

it('carries the PREVIOUS destination when settlement is re-pointed', function () {
    // The half the settings row structurally cannot hold: UNIQUE(school_id), one row, every write
    // overwrites. If the log does not carry `from`, where the money used to go is gone.
    $school = School::factory()->create();
    $actor = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $first = amdAccount($school, ['label' => 'Zenith — Fees']);
    $second = amdAccount($school, ['label' => 'GTB — Fees']);

    (new SetSettlementBankAccount)->handle($school->id, $first->uuid, $actor);
    (new SetSettlementBankAccount)->handle($school->id, $second->uuid, $actor);

    $row = amdRows('settlement_account_changed')->first();

    expect($row->properties['from']['bank_account_id'])->toBe($first->id)
        ->and($row->properties['to']['bank_account_id'])->toBe($second->id);
});

it('refuses to settle into a DEACTIVATED account, writing neither the row nor an entry', function () {
    $school = School::factory()->create();
    $actor = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school, ['deactivated_at' => now()]);

    expect(fn () => (new SetSettlementBankAccount)->handle($school->id, $account->uuid, $actor))
        ->toThrow(BusinessRuleException::class);

    expect(amdRows('settlement_account_changed'))->toHaveCount(0)
        ->and(DB::table('finance_school_settings')->where('school_id', $school->id)->count())->toBe(0);
});

it('refuses to settle into ANOTHER school\'s account', function () {
    $mine = School::factory()->create();
    $theirs = School::factory()->create();
    $actor = amdSeat($mine, ['finance.access', 'finance.bank-account.manage']);
    $foreign = amdAccount($theirs);

    expect(fn () => (new SetSettlementBankAccount)->handle($mine->id, $foreign->uuid, $actor))
        ->toThrow(BusinessRuleException::class);

    expect(amdRows('settlement_account_changed'))->toHaveCount(0);
});

// ── The console command, which is the surface Friday will actually use ──────────────────────────

it('REFUSES to set settlement without an actor, and writes nothing', function () {
    // --actor is not optional by oversight. A command that fell back to null would record the one
    // change this whole branch exists to attribute as having been made by nobody — which is the
    // state it is replacing, reached by a different route.
    $school = School::factory()->create();
    $account = amdAccount($school);

    $this->artisan('finance:set-settlement-account', [
        '--school' => $school->id,
        '--account' => $account->uuid,
    ])->assertFailed();

    expect(DB::table('finance_school_settings')->where('school_id', $school->id)->count())->toBe(0)
        ->and(amdRows('settlement_account_changed'))->toHaveCount(0);
});

it('writes NOTHING under --dry-run — not the row, and not an entry', function () {
    // A preview that leaves a trail claiming a change happened is worse than no preview.
    $school = School::factory()->create();
    $actor = amdSeat($school, ['finance.access']);
    $account = amdAccount($school);

    $this->artisan('finance:set-settlement-account', [
        '--school' => $school->id,
        '--account' => $account->uuid,
        '--actor' => (string) $actor->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(DB::table('finance_school_settings')->where('school_id', $school->id)->count())->toBe(0)
        ->and(amdRows('settlement_account_changed'))->toHaveCount(0);
});

it('sets settlement through the command, attributed to the named actor', function () {
    $school = School::factory()->create();
    $actor = amdSeat($school, ['finance.access']);
    $account = amdAccount($school);

    $this->artisan('finance:set-settlement-account', [
        '--school' => $school->id,
        '--account' => $account->uuid,
        '--actor' => $actor->email,
    ])->assertSuccessful();

    $settings = SchoolFinanceSettings::withoutGlobalScopes()
        ->where('school_id', $school->id)->firstOrFail();

    expect((int) $settings->settlement_bank_account_id)->toBe($account->id)
        ->and((int) $settings->settlement_bank_account_set_by_user_id)->toBe($actor->id);

    // Off-request, so School attribution rides ActiveSchool::runFor rather than the session. A row
    // filed under the wrong school is invisible to the auditor who needs it.
    expect((int) amdRows('settlement_account_changed')->first()->school_id)->toBe($school->id);
});

// ── The catalogue ───────────────────────────────────────────────────────────────────────────────

it('classifies every emitted event from the ROW it wrote, not from a declared key', function () {
    // Read log_name/event back OFF the row, so a rename of an emitted event reds this without
    // touching the catalogue and a wrong catalogue key reds it without touching the emitters.
    $school = School::factory()->create();
    $actor = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school);

    (new SetSettlementBankAccount)->handle($school->id, $account->uuid, $actor);

    $this->actingAs($actor)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts/'.$account->uuid.'/deactivate')->assertOk();

    $severity = ActivitySeverityService::make();

    $settlement = amdRows('settlement_account_changed')->first();
    $deactivation = amdRows('bank_account_deactivated')->first();

    // CRITICAL for settlement: one gesture, one person, and every naira of gateway income moves.
    expect($severity->for($settlement->log_name, $settlement->event))->toBe('critical')
        // WARNING for the account acts: none of them moves money by itself.
        ->and($severity->for($deactivation->log_name, $deactivation->event))->toBe('warning');
});

// ── The point: can the audit-only seat actually read any of this? ───────────────────────────────

it('is visible to the internal_auditor seat, which holds only activity_log.view/export/view_all', function () {
    $school = School::factory()->create();
    $bursar = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school);

    (new SetSettlementBankAccount)->handle($school->id, $account->uuid, $bursar);
    $row = amdRows('settlement_account_changed')->firstOrFail();

    $auditor = amdInternalAuditor($school);

    // The seat, as the seeder actually grants it — asserted rather than assumed, so this arm cannot
    // pass because a helper quietly handed it something else. BOTH directions: what it holds AND
    // what it must not, because "can read the audit feed" would be equally satisfied by a seat that
    // had been handed academic_data.view or finance.access to get it through the door.
    expect(amdAbilities($auditor, $school, [
        'activity_log.view', 'activity_log.export', 'activity_log.view_all',
        'activity_log.view_sensitive', 'academic_data.view', 'finance.access',
    ]))->toBe([
        'activity_log.view' => true,
        'activity_log.export' => true,
        'activity_log.view_all' => true,
        'activity_log.view_sensitive' => false,
        'academic_data.view' => false,
        'finance.access' => false,
    ]);

    // Addressed by ROW ID, not by a listing total: a count cannot tell "this row" from "some other
    // row this seat happens to be able to see".
    $this->actingAs($auditor)->withSession(['school_id' => $school->id])
        ->getJson('/api/activity-logs/'.$row->id)
        ->assertOk()
        ->assertJsonPath('data.event', 'settlement_account_changed')
        ->assertJsonPath('data.severity', 'critical');
});

it('is visible in the auditor\'s FEED, a row it did not cause', function () {
    // The show endpoint and the feed go through the same baseQuery, but the feed is what an auditor
    // opens. `causer_id` is the bursar throughout: without activity_log.view_all this seat sees
    // only rows it caused itself, which for an auditor is the empty set by construction.
    $school = School::factory()->create();
    $bursar = amdSeat($school, ['finance.access', 'finance.bank-account.manage']);
    $account = amdAccount($school);

    (new SetSettlementBankAccount)->handle($school->id, $account->uuid, $bursar);
    $row = amdRows('settlement_account_changed')->firstOrFail();

    expect($row->causer_id)->toBe($bursar->id);

    $auditor = amdInternalAuditor($school);

    $ids = collect(
        $this->actingAs($auditor)->withSession(['school_id' => $school->id])
            ->getJson('/api/activity-logs?per_page=100&event[]=settlement_account_changed')
            ->assertOk()
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($row->id);
});
