import { cn } from '@/lib/utils';
import type { AccountStatus, Money } from '@/types/finance';

// Derive the account's status from the SIGN of its balance. This is a sign check, not money
// arithmetic — the figure itself is never computed in JS (money-lint stays satisfied); the
// balance arrives already-computed from the API and we only read whether it is +, − or 0.
export function accountStatus(balance: Money): AccountStatus {
    if (balance.amount_minor > 0) {
        return 'outstanding';
    }

    if (balance.amount_minor < 0) {
        return 'in_credit';
    }

    return 'settled';
}

const STYLES: Record<AccountStatus, string> = {
    outstanding:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    in_credit:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    settled:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

const LABELS: Record<AccountStatus, string> = {
    outstanding: 'Outstanding',
    in_credit: 'In credit',
    settled: 'Settled',
};

/** The status pill used in the accounts table — colour-coded by balance sign. */
export function AccountStatusBadge({ balance }: { balance: Money }) {
    const status = accountStatus(balance);

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold',
                STYLES[status],
            )}
        >
            {LABELS[status]}
        </span>
    );
}
