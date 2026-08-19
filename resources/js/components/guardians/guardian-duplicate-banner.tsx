import { AlertTriangle, Link2, UserCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { GuardianDuplicateResult } from '@/hooks/use-guardian-lookup';

interface Props {
    result: GuardianDuplicateResult | null;
    /**
     * Offered only where there is somewhere to switch TO. The standalone
     * guardians page has no existing-guardian flow to hand over to, so it passes
     * nothing and the banner is warning-only.
     */
    onUseExisting?: (uuid: string) => void;
}

/**
 * WARN, LET THE OPERATOR CHOOSE. Deliberately not a block and deliberately not a
 * silent server-side reuse.
 *
 * A hard 422 is what the create path used to do (`Rule::unique('users','email')`)
 * and it is why a school added one mother once per child and ended up with three
 * rows: the correct action was refused and the workaround was not. Silent reuse is
 * the opposite failure — it discards the details just typed and leaves the operator
 * unsure which record they edited.
 *
 * Contacts arrive already masked from the server; nothing here unmasks or
 * reconstructs them.
 */
export function GuardianDuplicateBanner({ result, onUseExisting }: Props) {
    if (!result) {
        return null;
    }

    const { guardians, account } = result;

    if (guardians.length === 0 && !account?.exists) {
        return null;
    }

    return (
        <div className="space-y-3">
            {guardians.length > 0 && (
                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
                    <p className="flex items-center gap-2 text-xs font-semibold text-amber-900 dark:text-amber-200">
                        <AlertTriangle className="h-3.5 w-3.5" />A guardian
                        matching this already exists — link them to these
                        students instead?
                    </p>
                    <ul className="mt-2 space-y-2">
                        {guardians.map((candidate) => (
                            <li
                                key={candidate.uuid}
                                className="flex flex-wrap items-center justify-between gap-2 text-xs text-amber-900 dark:text-amber-100"
                            >
                                <span>
                                    <span className="font-medium">
                                        {candidate.full_name}
                                    </span>
                                    {candidate.masked_email && (
                                        <> · {candidate.masked_email}</>
                                    )}
                                    {candidate.masked_phone && (
                                        <> · {candidate.masked_phone}</>
                                    )}
                                    {' · '}
                                    {candidate.student_count === 1
                                        ? '1 child linked'
                                        : `${candidate.student_count} children linked`}
                                </span>
                                {onUseExisting ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            onUseExisting(candidate.uuid)
                                        }
                                    >
                                        <Link2 className="mr-1 h-3.5 w-3.5" />
                                        Link this guardian
                                    </Button>
                                ) : (
                                    <a
                                        className="underline"
                                        href={`/guardians/${candidate.uuid}`}
                                    >
                                        Open their record
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-2 text-[11px] text-amber-800 dark:text-amber-300">
                        If you continue, the existing record is reused and only
                        its empty fields are filled — nothing already recorded
                        is overwritten.
                    </p>
                </div>
            )}

            {guardians.length === 0 && account?.exists && (
                <div className="rounded-md border border-sky-300 bg-sky-50 p-3 dark:border-sky-800 dark:bg-sky-950/40">
                    <p className="flex items-center gap-2 text-xs font-semibold text-sky-900 dark:text-sky-200">
                        <UserCheck className="h-3.5 w-3.5" />
                        This address belongs to an existing account
                    </p>
                    {/*
                        NOT a duplicate guardian, and stated as its own case rather
                        than folded into one above. The address may belong to a
                        member of staff or a parent at another school; continuing
                        attaches this guardian to THAT account and grants it access
                        to this school, so the operator confirms it rather than
                        having it assumed for them.
                    */}
                    <p className="mt-1 text-[11px] text-sky-900 dark:text-sky-100">
                        {account.masked_email} is already registered, but is not
                        a guardian in this school
                        {account.has_access_to_school
                            ? '.'
                            : ' and has no access to it yet.'}{' '}
                        Continuing will attach this guardian to that account and
                        give it access to this school. Check it is the same
                        person before you save.
                    </p>
                </div>
            )}
        </div>
    );
}
