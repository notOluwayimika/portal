import axios from 'axios';
import { useCallback, useRef, useState } from 'react';

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
}

export type GuardianLookupStatus = 'idle' | 'loading' | 'found' | 'not_found' | 'error';

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

    const lookup = useCallback(async (identifier: string): Promise<GuardianLookupResult | null> => {
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
            const status = (err as { response?: { status?: number } })?.response?.status;
            if (status === 404) {
                setResult(null);
                setStatus('not_found');
                setError('No guardian found with that identifier in this school.');
            } else {
                setResult(null);
                setStatus('error');
                const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
                setError(message || 'Lookup failed. Please try again.');
            }
            return null;
        }
    }, [reset]);

    return { status, result, error, lookup, reset };
}
