import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface GuardianLookupResult {
    id: string;
    first_name: string;
    middle_name?: string | null;
    last_name: string;
    full_name: string;
    gender?: string | null;
    phone: string;
    whatsapp_number?: string | null;
    email?: string | null;
    occupation?: string | null;
    employer_name?: string | null;
    photo?: string | null;
    has_wards_in_other_schools: boolean;
    ward_schools: Array<{
        name: string;
        wards_count: number;
        is_current_school: boolean;
    }>;
}

export type GuardianLookupStatus =
    | 'idle'
    | 'loading'
    | 'found'
    | 'not_found'
    | 'error';

interface UseGuardianLookupReturn {
    status: GuardianLookupStatus;
    result: GuardianLookupResult | null;
    error: string | null;
    lookup: (identifier: string) => Promise<GuardianLookupResult | null>;
    reset: () => void;
}

/**
 * Calls GET /api/guardians/lookup. Cancels in-flight requests on re-entry.
 */
export function useGuardianLookup(): UseGuardianLookupReturn {
    const [status, setStatus] = useState<GuardianLookupStatus>('idle');
    const [result, setResult] = useState<GuardianLookupResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const abortRef = useRef<AbortController | null>(null);

    const reset = useCallback(() => {
        abortRef.current?.abort();
        setStatus('idle');
        setResult(null);
        setError(null);
    }, []);

    const lookup = useCallback(
        async (identifier: string): Promise<GuardianLookupResult | null> => {
            const trimmed = identifier.trim();

            if (!trimmed) {
                reset();

                return null;
            }

            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            setStatus('loading');
            setError(null);

            try {
                const res = await axios.get('/api/guardians/lookup', {
                    params: { identifier: trimmed },
                    signal: controller.signal,
                });
                const data = res.data?.data as GuardianLookupResult;
                setResult(data);
                setStatus('found');

                return data;
            } catch (err: unknown) {
                if (axios.isCancel(err)) {
                    return null;
                }

                const status = (err as { response?: { status?: number } })
                    ?.response?.status;

                if (status === 404) {
                    setResult(null);
                    setStatus('not_found');
                    setError('No guardian found with that identifier.');
                } else {
                    setResult(null);
                    setStatus('error');
                    const message = (
                        err as { response?: { data?: { message?: string } } }
                    )?.response?.data?.message;
                    setError(message || 'Lookup failed. Please try again.');
                }

                return null;
            }
        },
        [reset],
    );

    return { status, result, error, lookup, reset };
}

/**
 * The duplicate warning that fires BEFORE a create, not after.
 *
 * Lives in this file rather than a second hook module on purpose: this and
 * useGuardianLookup are the same plumbing — "find the guardian the operator is
 * describing" — and splitting them across files is how the two drift into
 * disagreeing about what a match is.
 *
 * They are separate HOOKS because they answer different questions to different
 * screens. useGuardianLookup searches ALL schools by one identifier and is the
 * existing-guardian attach flow; this one searches the ACTIVE school by the
 * email/phone the operator is typing into a CREATE form, and returns masked
 * candidates plus the "that address belongs to an account that is not a guardian
 * here" case, which the lookup endpoint has no notion of.
 */
export interface GuardianDuplicateCandidate {
    uuid: string;
    full_name: string;
    masked_email: string | null;
    masked_phone: string | null;
    student_count: number;
}

export interface GuardianDuplicateAccount {
    exists: boolean;
    masked_email: string | null;
    has_access_to_school: boolean;
}

export interface GuardianDuplicateResult {
    guardians: GuardianDuplicateCandidate[];
    account: GuardianDuplicateAccount | null;
}

interface DuplicateCheckInput {
    email?: string | null;
    phone?: string | null;
    whatsapp_number?: string | null;
}

const DEBOUNCE_MS = 400;

export function useGuardianDuplicateCheck() {
    const [result, setResult] = useState<GuardianDuplicateResult | null>(null);
    const [checking, setChecking] = useState(false);
    const abortRef = useRef<AbortController | null>(null);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // The component that owns this hook unmounts when its modal closes; without
    // this, an in-flight request resolves into a dead component and a pending
    // timer fires a request nobody is waiting for.
    useEffect(
        () => () => {
            abortRef.current?.abort();

            if (timerRef.current) {
                clearTimeout(timerRef.current);
            }
        },
        [],
    );

    const reset = useCallback(() => {
        abortRef.current?.abort();

        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        setResult(null);
        setChecking(false);
    }, []);

    const check = useCallback((input: DuplicateCheckInput) => {
        const params: Record<string, string> = {};

        if (input.email?.trim()) {
            params.email = input.email.trim();
        }

        if (input.phone?.trim()) {
            params.phone = input.phone.trim();
        }

        if (input.whatsapp_number?.trim()) {
            params.whatsapp_number = input.whatsapp_number.trim();
        }

        abortRef.current?.abort();

        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        if (Object.keys(params).length === 0) {
            setResult(null);
            setChecking(false);

            return;
        }

        setChecking(true);
        timerRef.current = setTimeout(() => {
            const controller = new AbortController();
            abortRef.current = controller;

            axios
                .get('/api/guardians/duplicate-check', {
                    params,
                    signal: controller.signal,
                })
                .then((res) => {
                    setResult(
                        (res.data?.data as GuardianDuplicateResult) ?? null,
                    );
                    setChecking(false);
                })
                .catch((err: unknown) => {
                    if (axios.isCancel(err)) {
                        return;
                    }

                    // A warning that cannot be fetched must never block or scare
                    // the operator: fall back to showing nothing. The server-side
                    // dedupe in createGuardianWithUser is the backstop for exactly
                    // this — proceeding without the warning costs a filled-in
                    // blank, not a duplicate row.
                    setResult(null);
                    setChecking(false);
                });
        }, DEBOUNCE_MS);
    }, []);

    return { result, checking, check, reset };
}
