<?php

/*
 * S6/U3 COMMIT 1 — the bank accounts money lands in.
 *
 * Three claims, and they fail for different reasons so they are asserted separately:
 *
 *   PERMISSION   finance.bank-account.manage gates every route. It is finance CONFIGURATION, so
 *                finance.access alone must not reach it — otherwise everyone who can view finance
 *                can change where money is recorded as landing.
 *   ISOLATION    a school cannot see or touch another school's accounts. The uniqueness key is
 *                per-school, so this is not merely a privacy claim: it is what makes two schools
 *                banking the same account number legal.
 *   NO DELETE    deactivation retires an account and leaves the row readable. An account that has
 *                received money must stay nameable forever; finance_payments is append-only, so a
 *                deleted account leaves a payment referring to nothing, permanently.
 */

use App\Finance\Models\BankAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * A seat in $school holding EXACTLY $permissions.
 *
 * @param  list<string>  $permissions
 */
function baSeat(School $school, array $permissions): User
{
    $roleName = 'ba_'.substr(md5(implode(',', $permissions)), 0, 10);
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

function baAccount(School $school, array $attributes = []): BankAccount
{
    return ActiveSchool::runFor($school->id, fn () => BankAccount::create(array_merge([
        'school_id' => $school->id,
        'label' => 'Zenith — Fees',
        'bank_name' => 'Zenith Bank',
        'account_number' => '10'.random_int(10000000, 99999999),
    ], $attributes)));
}

// ── The permission ──────────────────────────────────────────────────────────────────────────────

it('refuses every bank-account route to a seat holding only finance.access', function (string $method, string $path) {
    // THE ARM THAT MAKES THE PERMISSION WORTH COINING. If finance.access reached these routes the
    // new permission would be decoration, and every finance viewer could rewrite where money lands.
    $school = School::factory()->create();
    $account = baAccount($school);
    $viewer = baSeat($school, ['finance.access']);

    $url = str_replace('{uuid}', $account->uuid, $path);

    $this->actingAs($viewer)->withSession(['school_id' => $school->id])
        ->json($method, $url, ['label' => 'x', 'bank_name' => 'y', 'account_number' => '1'])
        ->assertForbidden();
})->with([
    'list' => ['GET', '/api/v1/finance/bank-accounts'],
    'create' => ['POST', '/api/v1/finance/bank-accounts'],
    'edit' => ['PATCH', '/api/v1/finance/bank-accounts/{uuid}'],
    'deactivate' => ['POST', '/api/v1/finance/bank-accounts/{uuid}/deactivate'],
    'reactivate' => ['POST', '/api/v1/finance/bank-accounts/{uuid}/reactivate'],
]);

it('admits a seat holding finance.bank-account.manage', function () {
    $school = School::factory()->create();
    $manager = baSeat($school, ['finance.access', 'finance.bank-account.manage']);

    $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts', [
            'label' => 'Zenith — Fees',
            'bank_name' => 'Zenith Bank',
            'account_number' => '1234567890',
            'account_name' => 'Brookstone Schools Ltd',
        ])
        ->assertCreated()
        ->assertJsonPath('label', 'Zenith — Fees')
        ->assertJsonPath('is_active', true);
});

it('grants the permission only to ungoverned roles, so a forcing migration cannot strip it', function () {
    // THE TRAP, PINNED. 2026_08_06_100000_move_head_of_school_finance_to_executive_director makes
    // head_of_school, accounts_supervisor and executive_director's `finance.` slice EQUAL a frozen
    // literal. A grant to any of those three is written by the seeder and REVOKED by that migration
    // on the next deploy — a failure that appears at deploy rather than at build, which is why it
    // gets an arm rather than a comment.
    $governed = ['head_of_school', 'accounts_supervisor', 'executive_director'];
    $holders = [];

    foreach (RbacSeeder::grantsMap() as $role => $permissions) {
        if (in_array('finance.bank-account.manage', (array) $permissions, true)) {
            $holders[] = $role;
        }
    }

    expect($holders)->not->toBeEmpty();
    expect(array_values(array_intersect($holders, $governed)))->toBe([],
        'finance.bank-account.manage is granted to a role governed by the forcing convergence '
        .'migration. The seeder will write that grant and the migration will revoke it on the next '
        .'deploy, silently. Either grant it to an ungoverned role, or ship a migration carrying an '
        .'@converges marker dated after the forcing one.');
});

// ── Isolation ───────────────────────────────────────────────────────────────────────────────────

it('cannot see or touch another school’s bank account', function () {
    $mine = School::factory()->create();
    $theirs = School::factory()->create();
    $foreign = baAccount($theirs);

    $manager = baSeat($mine, ['finance.access', 'finance.bank-account.manage']);

    // 404 at the route binding, not 403: BelongsToSchool's global scope means the row is not
    // findable under this School, so it is never authorised and then refused — it never resolves.
    $this->actingAs($manager)->withSession(['school_id' => $mine->id])
        ->patchJson('/api/v1/finance/bank-accounts/'.$foreign->uuid, [
            'label' => 'Hijacked', 'bank_name' => 'X', 'account_number' => '999',
        ])
        ->assertNotFound();

    $this->actingAs($manager)->withSession(['school_id' => $mine->id])
        ->getJson('/api/v1/finance/bank-accounts')
        ->assertOk()
        ->assertJsonCount(0, 'bank_accounts');
});

it('lets two different schools bank the same account number', function () {
    // The per-school uniqueness key, asserted as a CAPABILITY rather than as a constraint. A global
    // unique would refuse this, and it is legitimate: the two schools are separate legal entities
    // and may genuinely share banking.
    $a = School::factory()->create();
    $b = School::factory()->create();

    baAccount($a, ['account_number' => '1234567890']);
    baAccount($b, ['account_number' => '1234567890']);

    expect(BankAccount::withoutGlobalScopes()->where('account_number', '1234567890')->count())->toBe(2);
});

it('refuses a duplicate account number within one school', function () {
    $school = School::factory()->create();
    baAccount($school, ['account_number' => '1234567890']);
    $manager = baSeat($school, ['finance.access', 'finance.bank-account.manage']);

    $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts', [
            'label' => 'Duplicate', 'bank_name' => 'Zenith Bank', 'account_number' => '1234567890',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account_number']);
});

// ── Deactivation is not deletion ────────────────────────────────────────────────────────────────

it('deactivates without deleting, and the row stays listed', function () {
    // THE CLAIM THE WHOLE TABLE DEPENDS ON. A deactivated account must remain readable — a payment
    // reconciled against it months ago is unexplainable otherwise, and finance_payments is
    // append-only so the reference can never be rewritten to point elsewhere.
    $school = School::factory()->create();
    $account = baAccount($school);
    $manager = baSeat($school, ['finance.access', 'finance.bank-account.manage']);

    $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts/'.$account->uuid.'/deactivate')
        ->assertOk()
        ->assertJsonPath('is_active', false);

    expect(BankAccount::withoutGlobalScopes()->whereKey($account->id)->exists())->toBeTrue(
        'Deactivating DELETED the row. An account that has received money must stay nameable '
        .'forever; a deleted one leaves every payment that referenced it unexplainable.');

    $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/bank-accounts')
        ->assertOk()
        ->assertJsonCount(1, 'bank_accounts')
        ->assertJsonPath('bank_accounts.0.is_active', false);
});

it('keeps the original retirement timestamp when deactivated twice', function () {
    // "When did we stop using this account" has ONE answer. A second click must not rewrite it —
    // that date is what a reconciliation reads to explain why an account stopped appearing.
    $school = School::factory()->create();
    $account = baAccount($school);
    $manager = baSeat($school, ['finance.access', 'finance.bank-account.manage']);

    $act = fn () => $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/bank-accounts/'.$account->uuid.'/deactivate');

    $first = $act()->json('deactivated_at');
    $this->travel(2)->minutes();
    $second = $act()->json('deactivated_at');

    expect($second)->toBe($first);
});

it('registers NO destroy route for bank accounts', function () {
    // THE ABSENCE, ASSERTED. Every other claim here is about behaviour that exists; this one is
    // about behaviour that must never be added. Someone will propose a delete button in month
    // three, and a comment would not stop them — this fails their build.
    $destroyRoutes = [];

    foreach (Route::getRoutes() as $route) {
        if (str_contains($route->uri(), 'bank-account') && in_array('DELETE', $route->methods(), true)) {
            $destroyRoutes[] = $route->uri();
        }
    }

    expect($destroyRoutes)->toBe([],
        'A DELETE route exists for bank accounts: '.implode(', ', $destroyRoutes).'. There must not '
        .'be one. An account that has received money must stay nameable forever — retirement is '
        .'deactivate, which withdraws it from choice and leaves every historical reference '
        .'resolvable. See the migration docblock.');
});

// ── Identifying fields are immutable, in three independently-tested layers ───────────────────────

/*
 * THREE LAYERS, THREE ARMS, THREE DIFFERENT OUTCOMES. Each is asserted on what it PRODUCES, not on
 * whether a check is present — 5b-ii's RED 2 lesson. Removing any one layer fails its own arm with
 * its own status, so a guard cannot pass by being covered for by its neighbours.
 *
 *   database   1644, whatever route reached it
 *   request    422 with a sentence naming the way out
 *   screen     the fields are not rendered as inputs when editing
 */

it('LAYER 1 (database): the trigger refuses a change to an identifying field', function () {
    // Reached by writing THROUGH THE MODEL, past the FormRequest and past the controller's narrowed
    // update list — which is the only way to observe this layer at all, and the point of having it:
    // a console command, a future Action or a careless tinker session all land here.
    $school = School::factory()->create();
    $account = baAccount($school, ['account_number' => '1234567890']);

    $code = null;
    $message = '';

    try {
        ActiveSchool::runFor($school->id, fn () => DB::table('finance_bank_accounts')
            ->where('id', $account->id)
            ->update(['account_number' => '9999999999']));
    } catch (QueryException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $message = (string) ($e->errorInfo[2] ?? '');
    }

    expect($code)->toBe(1644,
        'The database did not refuse a change to account_number. The trigger is the layer that holds '
        .'when the request layer is bypassed — a console command or a future Action reaches the table '
        .'directly, and an account number that can change silently rewrites where past money went.');

    expect(str_contains($message, 'deactivate this account and create a new one'))->toBeTrue(
        'The refusal does not say what to do instead. Got: '.$message);
});

it('LAYER 1 (database): the SIGNAL message obeys both mysqldump constraints', function () {
    // MEASURED AGAINST THE LIVE TRIGGER, not against the migration file — the file is what we wrote,
    // the trigger is what runs. Both limits cost real time to learn: an apostrophe breaks mysqldump
    // (an unrestorable backup, discovered at the worst moment), and over 128 characters SIGNAL fails
    // with 1648 instead of raising 1644, so the guard reports the wrong error entirely.
    $definition = (string) DB::selectOne(
        'SELECT ACTION_STATEMENT s FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
        ['finance_bank_accounts_identity_immutable'],
    )->s;

    preg_match("/MESSAGE_TEXT\s*=\s*'([^']*)'/", $definition, $m);
    $text = $m[1] ?? '';

    expect($text)->not->toBe('');
    expect(strlen($text))->toBeLessThanOrEqual(128,
        'The SIGNAL message is '.strlen($text).' characters. Over 128 and SIGNAL fails with 1648 '
        .'instead of raising 1644, so the guard reports a MySQL error rather than its own refusal.');
    // Matched on the extracted TEXT rather than on an inline "MESSAGE_TEXT = 'Bank name" — MySQL
    // stores the statement with its original line breaks, so the literal begins on the line AFTER
    // the assignment and an inline match silently never holds. Measured, not assumed.
    expect(str_contains($text, 'deactivate this account and create a new one'))->toBeTrue(
        'The trigger message does not name the way out. Got: '.$text);
    expect(str_contains($text, "'"))->toBeFalse(
        'The SIGNAL message contains an apostrophe. mysqldump breaks on one, which turns a backup '
        .'into an unrestorable file — a defect that surfaces at the worst possible moment.');
});

it('LAYER 2 (request): editing an identifying field is a 422 naming the way out', function () {
    // NOT a 500 from the trigger, and not a silent drop. An operator whose bank details have really
    // changed needs to be told to deactivate and create a new account; "immutable" alone tells them
    // nothing they can act on, and silently ignoring the field would let them believe it worked.
    $school = School::factory()->create();
    $account = baAccount($school, ['account_number' => '1234567890']);
    $manager = baSeat($school, ['finance.access', 'finance.bank-account.manage']);

    $response = $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->patchJson('/api/v1/finance/bank-accounts/'.$account->uuid, [
            'label' => 'Renamed',
            'account_number' => '9999999999',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['account_number']);

    expect(str_contains(
        (string) $response->json('errors.account_number.0'),
        'Deactivate this account and create a new one',
    ))->toBeTrue('The 422 does not name the way out. Got: '.$response->json('errors.account_number.0'));

    // And nothing moved.
    expect($account->refresh()->account_number)->toBe('1234567890');
});

it('LAYER 2 (request): the editable labels still change', function () {
    // The other direction, so the arm above is not passing because editing is broken outright.
    $school = School::factory()->create();
    $account = baAccount($school, ['label' => 'Old label']);
    $manager = baSeat($school, ['finance.access', 'finance.bank-account.manage']);

    $this->actingAs($manager)->withSession(['school_id' => $school->id])
        ->patchJson('/api/v1/finance/bank-accounts/'.$account->uuid, [
            'label' => 'New label',
            'account_name' => 'Brookstone Schools Ltd',
        ])
        ->assertOk()
        ->assertJsonPath('label', 'New label');
});

it('LAYER 3 (screen): the identity fields are not rendered as inputs when editing', function () {
    // A DISABLED INPUT THAT POSTS ANYWAY IS NOT A GUARD, and a readonly one still tells the operator
    // the field is theirs to argue with. Asserted on the source because there is no JS test runner
    // here (the same reason ApprovalsQueueFeedCoverageTest reads TypeScript as text) — and asserted
    // on the SHAPE that produces the behaviour: the edit branch's field list must not name them.
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/js/pages/admin/finance/bank-accounts.tsx');

    // The create branch offers them; the edit branch must not. Split on the ternary that chooses.
    $editBranch = explode('{(editing', $source)[1] ?? '';
    $editBranch = explode(': (', $editBranch)[0] ?? '';

    expect($editBranch)->not->toBe('');
    expect(str_contains($editBranch, "'bank_name'"))->toBeFalse(
        'The edit branch of the modal still renders bank_name as an input. It must not be editable '
        .'at all — not disabled, not readonly. A disabled input that posts its value is not a guard.');
    expect(str_contains($editBranch, "'account_number'"))->toBeFalse(
        'The edit branch of the modal still renders account_number as an input.');

    // And the operator is TOLD why, with the way out — otherwise the fields have simply vanished.
    //
    // WHITESPACE-NORMALISED FIRST, because Prettier reflows JSX prose across lines: the sentence in
    // the component is broken over six of them, so a raw str_contains for any multi-word phrase is
    // a check that can only fail. This arm failed for exactly that reason before it was fixed.
    $flat = (string) preg_replace('/\s+/', ' ', $source);

    expect(str_contains($flat, 'deactivate this account and add a new one'))->toBeTrue(
        'The edit modal does not explain why the bank and account number are missing. A field that '
        .'silently disappears reads as a bug, not as a rule.');
});
