<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Http\Requests\StoreBankAccountRequest;
use App\Finance\Http\Requests\UpdateBankAccountRequest;
use App\Finance\Models\BankAccount;
use App\Http\Controllers\Controller;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;

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
        $account = BankAccount::create([
            // Explicit rather than relying on the model's creating hook, so the row's School is
            // stated at the write and a missing context fails here rather than landing somewhere.
            'school_id' => ActiveSchool::getOrFail()->id,
            'label' => $request->string('label')->toString(),
            'bank_name' => $request->string('bank_name')->toString(),
            'account_number' => $request->string('account_number')->toString(),
            'account_name' => $request->input('account_name'),
        ]);

        return response()->json($this->present($account), 201);
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): JsonResponse
    {
        // LABELS ONLY. bank_name and account_number are immutable from creation — see the
        // identity-immutable migration. The update list is the third statement of that rule (the
        // trigger refuses, the FormRequest explains, this cannot even attempt it), and the narrow
        // list is what makes the trigger unreachable by the ordinary path rather than merely
        // survivable.
        $bankAccount->update($request->only(['label', 'account_name']));

        return response()->json($this->present($bankAccount->refresh()));
    }

    /**
     * Retire an account without erasing it.
     *
     * Idempotent: deactivating an already-deactivated account keeps the ORIGINAL timestamp rather
     * than moving it. "When did we stop using this" has one answer, and a second click must not
     * rewrite it.
     */
    public function deactivate(BankAccount $bankAccount): JsonResponse
    {
        if ($bankAccount->isActive()) {
            $bankAccount->update(['deactivated_at' => now()]);
        }

        return response()->json($this->present($bankAccount->refresh()));
    }

    /** Reactivate — the mirror, for an account retired by mistake. */
    public function reactivate(BankAccount $bankAccount): JsonResponse
    {
        $bankAccount->update(['deactivated_at' => null]);

        return response()->json($this->present($bankAccount->refresh()));
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
