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
    /**
     * The `confirm_existing_account` answer. Owned by the form, not by this
     * component, because it is submitted with the payload and the server refuses
     * without it.
     */
    confirmedAccount?: boolean;
    onConfirmAccountChange?: (value: boolean) => void;
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
export function GuardianDuplicateBanner({
    result,
    onUseExisting,
    confirmedAccount,
    onConfirmAccountChange,
}: Props) {
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
                        {/*
                            CORRECTED — this used to promise "nothing already
                            recorded is overwritten", which was true of the
                            guardian row and FALSE of the student links: the
                            reuse path re-ran the pivot writer, so re-entering a
                            child who was already linked overwrote their
                            relationship, primary flag and portal-login flag from
                            this form's defaults. The server now leaves an
                            existing link alone; this sentence says so, because a
                            banner that describes behaviour the code does not have
                            is worse than no banner.
                        */}
                        If you continue, the existing record is reused: only its
                        empty fields are filled, and any child already linked to
                        them is left exactly as it is — including who is primary
                        and whether they can log in. To change an existing link,
                        open their record.
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
                        to this school.

                        THE CHECKBOX IS THE CONTROL AND THE SERVER IS THE GATE. This
                        panel used to say "check it is the same person before you
                        save" and offer nothing to check it with: the sentence was
                        the whole mechanism, and the server bound the account either
                        way. The server now refuses without confirm_existing_account,
                        so this control is how the operator supplies it — and a
                        client that never renders this panel fails closed rather than
                        binding by omission.
                    */}
                    <p className="mt-1 text-[11px] text-sky-900 dark:text-sky-100">
                        {account.masked_email} is already registered, but is not
                        a guardian in this school
                        {account.has_access_to_school
                            ? '.'
                            : ' and has no access to it yet.'}{' '}
                        Continuing will attach this guardian to that account and
                        give it access to this school. If it belongs to someone
                        else — a colleague, say — disabling this guardian's
                        login later will lock that person out everywhere.
                    </p>
                    {onConfirmAccountChange && (
                        <label className="mt-2 flex items-start gap-2 text-[11px] text-sky-900 dark:text-sky-100">
                            <input
                                type="checkbox"
                                className="mt-0.5"
                                checked={confirmedAccount ?? false}
                                onChange={(e) =>
                                    onConfirmAccountChange(e.target.checked)
                                }
                            />
                            Yes — this is the same person. Link this guardian to
                            that account.
                        </label>
                    )}
                </div>
            )}
        </div>
    );
}
