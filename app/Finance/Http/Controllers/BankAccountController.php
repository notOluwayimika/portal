<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Http\Requests\StoreBankAccountRequest;
use App\Finance\Http\Requests\UpdateBankAccountRequest;
use App\Finance\Models\BankAccount;
use App\Finance\Models\SchoolFinanceSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-School bank-account CRUD — create, list, edit, deactivate.
 *
 * THERE IS NO destroy(), AND ITS ABSENCE IS THE DESIGN. A bank account that has received money must
 * remain nameable forever; `deactivate()` is the only retirement. See the migration's docblock for
 * what deletion would cost a reconciliation, and BankAccountRouteTest for the arm that fails if a
 * destroy route ever appears.
 *
 * Every read and write is School-scoped by BelongsToSchool's global scope, so a foreign uuid 404s at
 * the route binding rather than being authorised and then found empty.
 *
 * EVERY WRITE HERE IS AN AUDITED ACT, and it is written TWICE on purpose:
 *
 *   the ROW    `created_by_user_id` / `updated_by_user_id` / `deactivated_by_user_id` — who is
 *              responsible for the account as it stands RIGHT NOW. One join, no log to read.
 *   the LOG    a `finance.bank_account_*` activity entry per act — what this account has EVER
 *              been, in sequence, with the before/after of the fields that moved.
 *
 * Neither derives from the other. The row can only hold the current answer; the log is the only
 * place the previous one survives. Deactivation is the case that proves it: a retirement in March
 * followed by a label correction in September leaves `updated_by_user_id` naming the September
 * editor, and only the log still says who retired the account — which is the question a
 * reconciliation asks first.
 *
 * THE ACCOUNT NUMBER IS NOT LOGGED. `activity_log` rows are readable by every holder of
 * `activity_log.view` — including `internal_auditor`, which is the point of the events — and these
 * entries are deliberately NOT in `config/activity_log_sensitive.php`. The uuid, label and bank
 * name identify the account for anyone reading the trail; the number itself is one join away for
 * anyone with `finance.bank-account.manage` and does not need copying into a wider-read table.
 */
class BankAccountController extends Controller
{
    /** The school's accounts, active first — the order the picker in commit 2 will want. */
    public function index(): JsonResponse
    {
        $accounts = BankAccount::query()->inDisplayOrder()->get();

        return response()->json(['bank_accounts' => $accounts->map($this->present(...))->all()]);
    }

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $actor = $request->user();

        $account = BankAccount::create([
            // Explicit rather than relying on the model's creating hook, so the row's School is
            // stated at the write and a missing context fails here rather than landing somewhere.
            'school_id' => ActiveSchool::getOrFail()->id,
            'label' => $request->string('label')->toString(),
            'bank_name' => $request->string('bank_name')->toString(),
            'account_number' => $request->string('account_number')->toString(),
            'account_name' => $request->input('account_name'),
            'created_by_user_id' => $actor?->getKey(),
            'updated_by_user_id' => $actor?->getKey(),
        ]);

        $this->audit($account, 'bank_account_created', $actor);

        return response()->json($this->present($account), 201);
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): JsonResponse
    {
        // LABELS ONLY. bank_name and account_number are immutable from creation — see the
        // identity-immutable migration. The update list is the third statement of that rule (the
        // trigger refuses, the FormRequest explains, this cannot even attempt it), and the narrow
        // list is what makes the trigger unreachable by the ordinary path rather than merely
        // survivable.
        $actor = $request->user();

        $before = $bankAccount->only(['label', 'account_name']);

        $bankAccount->update($request->only(['label', 'account_name']) + [
            'updated_by_user_id' => $actor?->getKey(),
        ]);

        $this->audit($bankAccount, 'bank_account_updated', $actor, [
            'from' => $before,
            'to' => $bankAccount->only(['label', 'account_name']),
        ]);

        return response()->json($this->present($bankAccount->refresh()));
    }

    /**
     * Retire an account without erasing it.
     *
     * Idempotent: deactivating an already-deactivated account keeps the ORIGINAL timestamp rather
     * than moving it. "When did we stop using this" has one answer, and a second click must not
     * rewrite it.
     */
    public function deactivate(Request $request, BankAccount $bankAccount): JsonResponse
    {
        // The idempotent branch writes NOTHING — no timestamp, no actor, and no activity row.
        // A second click must not restate who retired the account any more than it may move the
        // date, and an audit trail that grows a row every time somebody re-clicks is one an auditor
        // learns to skim.
        if ($bankAccount->isActive()) {
            $actor = $request->user();

            // IS THIS THE ACCOUNT THE SCHOOL SETTLES INTO? Nothing refuses the deactivation if it
            // is — a two-step swap (retire the old, point at the new) is legitimate, and refusing
            // would block it. But `SettlementBankAccount::forSchool()` keeps returning this id
            // whether or not the account is retired, so gateway money would carry on arriving in an
            // account the school has said it no longer uses, silently. The property makes that
            // visible to the auditor at the moment it happens, which is the whole thesis of this
            // change. The GUARD is a separate decision and is ticketed:
            // docs/handoff/tickets/deactivating-the-settlement-account-is-not-refused.md
            $wasSettlement = SchoolFinanceSettings::query()
                ->where('school_id', $bankAccount->school_id)
                ->value('settlement_bank_account_id') === $bankAccount->id;

            $bankAccount->update([
                'deactivated_at' => now(),
                'deactivated_by_user_id' => $actor?->getKey(),
                'updated_by_user_id' => $actor?->getKey(),
            ]);

            $this->audit($bankAccount, 'bank_account_deactivated', $actor, [
                'was_settlement_account' => $wasSettlement,
            ]);
        }

        return response()->json($this->present($bankAccount->refresh()));
    }

    /**
     * Reactivate — the mirror, for an account retired by mistake.
     *
     * `deactivated_by_user_id` is CLEARED alongside `deactivated_at`: the pair describes the current
     * retirement, and there is none. Who retired it, and who restored it, are both in the log —
     * which is the division this class's docblock sets out.
     *
     * Not guarded on `isActive()` the way deactivate() is, deliberately: reactivating an already
     * active account is a no-op on the columns but still an act somebody performed, and the write
     * of `updated_by_user_id` is what makes the log entry's actor true rather than inherited.
     */
    public function reactivate(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $actor = $request->user();

        $bankAccount->update([
            'deactivated_at' => null,
            'deactivated_by_user_id' => null,
            'updated_by_user_id' => $actor?->getKey(),
        ]);

        $this->audit($bankAccount, 'bank_account_reactivated', $actor);

        return response()->json($this->present($bankAccount->refresh()));
    }

    /**
     * One activity row per act, under the `finance` log name so it files with the Action rows the
     * rest of the module writes.
     *
     * Every key here is declared in config/activity_log_severity.php; a new one that is not fails
     * bin/ci-activity-catalogue-lint.php rather than falling through to `info` unnoticed.
     *
     * `$event` is a parameter, so no static reading can resolve it. The catalogue lint refuses to
     * SKIP what it cannot parse, so the four values its callers pass are declared here and then held
     * to the catalogue like any other emitter — an unclassified one still reds. The residual is that
     * nothing checks this list against the four call sites; keep them in step.
     *
     * @activity-emits finance.bank_account_created
     * @activity-emits finance.bank_account_updated
     * @activity-emits finance.bank_account_deactivated
     * @activity-emits finance.bank_account_reactivated
     *
     * @param  array<string, mixed>  $properties
     */
    private function audit(BankAccount $account, string $event, ?User $actor, array $properties = []): void
    {
        activity('finance')
            ->performedOn($account)
            ->causedBy($actor)
            ->event($event)
            ->withProperties($properties + [
                // Identity WITHOUT the account number — see the class docblock.
                'bank_account_uuid' => $account->uuid,
                'label' => $account->label,
                'bank_name' => $account->bank_name,
            ])
            ->log($event.': '.$account->label);
    }

    /** @return array<string, mixed> */
    private function present(BankAccount $account): array
    {
        return [
            'id' => $account->uuid,
            'label' => $account->label,
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number,
            'account_name' => $account->account_name,
            'is_active' => $account->isActive(),
            'deactivated_at' => $account->deactivated_at?->toIso8601String(),
        ];
    }
}
