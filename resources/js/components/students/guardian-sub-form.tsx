import axios from 'axios';
import { Plus, Search, Trash2, UserCheck, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useGuardianLookup, type GuardianLookupResult } from '@/hooks/use-guardian-lookup';

export interface GuardianFormEntry {
    mode: 'new' | 'existing';
    relationship: string;
    is_primary: boolean;
    can_login: boolean;

    // existing
    guardian_id?: string;
    identifier?: string;
    looked_up?: GuardianLookupResult | null;

    // new
    first_name?: string;
    middle_name?: string;
    last_name?: string;
    gender?: string;
    phone?: string;
    whatsapp_number?: string;
    email?: string;
    city?: string;
    state?: string;
    country?: string;
    postal_code?: string;
    occupation?: string;
    employer_name?: string;
    marital_status?: string;
    emergency_contact?: string;
    id_type?: string;
    id_number?: string;
    id_expiry_date?: string;
}

export interface Option {
    name: string;
    value: string;
}

export interface GuardianResources {
    genders: Option[];
    id_types: Option[];
    relationships: Option[];
    marital_statuses: Option[];
}

interface GuardianSubFormProps {
    value: GuardianFormEntry[];
    onChange: (next: GuardianFormEntry[]) => void;
    errors?: Record<string, string>;
}

export function emptyGuardianEntry(overrides: Partial<GuardianFormEntry> = {}): GuardianFormEntry {
    return {
        mode: 'new',
        relationship: '',
        is_primary: false,
        can_login: false,
        first_name: '',
        last_name: '',
        phone: '',
        ...overrides,
    };
}

export function GuardianSubForm({ value, onChange, errors = {} }: GuardianSubFormProps) {
    const [resources, setResources] = useState<GuardianResources>({ genders: [], id_types: [], relationships: [], marital_statuses: [] });

    useEffect(() => {
        let mounted = true;
        axios.get('/api/guardians/resources')
            .then((res) => {
                if (mounted) setResources(res.data.data ?? res.data);
            })
            .catch(() => {});
        return () => { mounted = false; };
    }, []);

    const updateEntry = (index: number, patch: Partial<GuardianFormEntry>) => {
        const next = value.map((entry, i) => (i === index ? { ...entry, ...patch } : entry));
        // Enforce a single primary across the array.
        if (patch.is_primary === true) {
            next.forEach((entry, i) => {
                if (i !== index) entry.is_primary = false;
            });
        }
        onChange(next);
    };

    const removeEntry = (index: number) => {
        const next = value.filter((_, i) => i !== index);
        // If we removed the primary, mark the first remaining as primary.
        if (next.length > 0 && !next.some((g) => g.is_primary)) {
            next[0].is_primary = true;
        }
        onChange(next);
    };

    const addEntry = () => {
        onChange([...value, emptyGuardianEntry({ is_primary: value.length === 0 })]);
    };

    const fieldError = (index: number, field: string): string | undefined =>
        errors[`guardians.${index}.${field}`];

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between border-t pt-4">
                <div>
                    <h3 className="text-base font-semibold">Guardians</h3>
                    <p className="text-muted-foreground text-xs">
                        Add at least one guardian. Exactly one must be marked as primary.
                    </p>
                </div>
                <Button type="button" variant="outline" size="sm" onClick={addEntry}>
                    <Plus className="mr-1 h-4 w-4" />
                    Add Guardian
                </Button>
            </div>

            {errors['guardians'] && (
                <p className="text-destructive text-xs">{errors['guardians']}</p>
            )}

            {value.length === 0 && (
                <div className="border-muted-foreground/30 text-muted-foreground rounded-md border border-dashed p-4 text-center text-sm">
                    No guardians added yet. Click "Add Guardian" to begin.
                </div>
            )}

            <div className="space-y-4">
                {value.map((entry, index) => (
                    <GuardianRow
                        key={index}
                        index={index}
                        entry={entry}
                        resources={resources}
                        onChange={(patch) => updateEntry(index, patch)}
                        onRemove={() => removeEntry(index)}
                        canRemove={value.length > 1}
                        getError={(field) => fieldError(index, field)}
                    />
                ))}
            </div>
        </div>
    );
}

interface GuardianRowProps {
    index: number;
    entry: GuardianFormEntry;
    resources: GuardianResources;
    onChange: (patch: Partial<GuardianFormEntry>) => void;
    onRemove: () => void;
    canRemove: boolean;
    getError: (field: string) => string | undefined;
}

export function GuardianRow({ index, entry, resources, onChange, onRemove, canRemove, getError }: GuardianRowProps) {
    const { status, result, error: lookupError, lookup, reset: resetLookup } = useGuardianLookup();
    const [identifierDraft, setIdentifierDraft] = useState(entry.identifier ?? '');

    const handleLookup = async () => {
        const found = await lookup(identifierDraft);
        if (found) {
            onChange({
                guardian_id: found.id,
                identifier: identifierDraft,
                looked_up: found,
            });
        } else {
            onChange({ guardian_id: undefined, looked_up: null, identifier: identifierDraft });
        }
    };

    const toggleMode = (isNew: boolean) => {
        if (isNew) {
            // Case A — switch to "new"; clear existing-specific fields.
            resetLookup();
            onChange({
                mode: 'new',
                guardian_id: undefined,
                identifier: undefined,
                looked_up: null,
            });
            setIdentifierDraft('');
        } else {
            onChange({ mode: 'existing' });
        }
    };

    return (
        <div className="space-y-4 rounded-lg border p-4">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-3 border-b pb-3">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="text-sm font-semibold">Guardian {index + 1}</span>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={entry.is_primary}
                            onCheckedChange={(c) => onChange({ is_primary: Boolean(c) })}
                        />
                        Primary
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={entry.can_login}
                            onCheckedChange={(c) => onChange({ can_login: Boolean(c) })}
                        />
                        Can log in
                    </label>
                </div>
                {canRemove && (
                    <Button type="button" variant="ghost" size="sm" onClick={onRemove} className="text-destructive">
                        <Trash2 className="h-4 w-4" />
                    </Button>
                )}
            </div>

            {/* Mode toggle */}
            <div className="flex items-center gap-2">
                <Checkbox
                    id={`guardian-${index}-new`}
                    checked={entry.mode === 'new'}
                    onCheckedChange={(c) => toggleMode(Boolean(c))}
                />
                <Label htmlFor={`guardian-${index}-new`} className="cursor-pointer text-sm font-normal">
                    I don't have any other child in this school
                    <span className="text-muted-foreground ml-1 text-xs">
                        (check this for a brand-new guardian; uncheck to look up an existing one)
                    </span>
                </Label>
            </div>

            {/* Relationship */}
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="space-y-2">
                    <Label>Relationship</Label>
                    <Select value={entry.relationship} onValueChange={(v) => onChange({ relationship: v })}>
                        <SelectTrigger>
                            <SelectValue placeholder="Select relationship" />
                        </SelectTrigger>
                        <SelectContent>
                            {resources.relationships.map((r) => (
                                <SelectItem key={r.value} value={r.value}>{r.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {getError('relationship') && <p className="text-destructive text-xs">{getError('relationship')}</p>}
                </div>
            </div>

            {entry.mode === 'existing' ? (
                <ExistingGuardianBody
                    identifierDraft={identifierDraft}
                    setIdentifierDraft={setIdentifierDraft}
                    status={status}
                    result={entry.looked_up ?? result}
                    lookupError={lookupError}
                    onLookup={handleLookup}
                    getError={getError}
                />
            ) : (
                <NewGuardianBody entry={entry} resources={resources} onChange={onChange} getError={getError} />
            )}
        </div>
    );
}

interface ExistingBodyProps {
    identifierDraft: string;
    setIdentifierDraft: (v: string) => void;
    status: ReturnType<typeof useGuardianLookup>['status'];
    result: GuardianLookupResult | null | undefined;
    lookupError: string | null;
    onLookup: () => void;
    getError: (field: string) => string | undefined;
}

function ExistingGuardianBody({
    identifierDraft,
    setIdentifierDraft,
    status,
    result,
    lookupError,
    onLookup,
    getError,
}: ExistingBodyProps) {
    return (
        <div className="space-y-3">
            <div className="space-y-2">
                <Label>Look up existing guardian (email or phone)</Label>
                <div className="flex gap-2">
                    <Input
                        placeholder="parent@example.com or 08012345678"
                        value={identifierDraft}
                        onChange={(e) => setIdentifierDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                onLookup();
                            }
                        }}
                    />
                    <Button type="button" variant="outline" onClick={onLookup} disabled={status === 'loading'}>
                        {status === 'loading' ? (
                            <Spinner className="mr-1 h-4 w-4 animate-spin" />
                        ) : (
                            <Search className="mr-1 h-4 w-4" />
                        )}
                        Look up
                    </Button>
                </div>
                {(getError('guardian_id') || getError('identifier')) && (
                    <p className="text-destructive text-xs">{getError('guardian_id') ?? getError('identifier')}</p>
                )}
                {status === 'not_found' && (
                    <p className="text-destructive text-xs">{lookupError}</p>
                )}
                {status === 'error' && lookupError && (
                    <p className="text-destructive text-xs">{lookupError}</p>
                )}
            </div>

            {result && (
                <div className="bg-muted/30 flex items-start gap-3 rounded-md border p-3">
                    <UserCheck className="text-primary mt-0.5 h-5 w-5 flex-shrink-0" />
                    <div className="space-y-1 text-sm">
                        <p className="font-medium">{result.full_name}</p>
                        <p className="text-muted-foreground text-xs">
                            Phone: {result.phone}
                            {result.email && <> · Email: {result.email}</>}
                        </p>
                        {result.occupation && (
                            <p className="text-muted-foreground text-xs">Occupation: {result.occupation}</p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

interface NewBodyProps {
    entry: GuardianFormEntry;
    resources: GuardianResources;
    onChange: (patch: Partial<GuardianFormEntry>) => void;
    getError: (field: string) => string | undefined;
}

function NewGuardianBody({ entry, resources, onChange, getError }: NewBodyProps) {
    return (
        <div className="space-y-4">
            <div className="text-muted-foreground flex items-center gap-2 text-xs">
                <UserPlus className="h-3.5 w-3.5" /> Creating a new guardian
            </div>

            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <Field label="First Name" required error={getError('first_name')}>
                    <Input value={entry.first_name ?? ''} onChange={(e) => onChange({ first_name: e.target.value })} required />
                </Field>
                <Field label="Last Name" required error={getError('last_name')}>
                    <Input value={entry.last_name ?? ''} onChange={(e) => onChange({ last_name: e.target.value })} required />
                </Field>
                <Field label="Middle Name">
                    <Input value={entry.middle_name ?? ''} onChange={(e) => onChange({ middle_name: e.target.value })} />
                </Field>
                <Field label="Gender">
                    <Select value={entry.gender ?? ''} onValueChange={(v) => onChange({ gender: v })}>
                        <SelectTrigger><SelectValue placeholder="Select gender" /></SelectTrigger>
                        <SelectContent>
                            {resources.genders.map((g) => (
                                <SelectItem key={g.value} value={g.value}>{g.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field label="Phone" required error={getError('phone')}>
                    <Input value={entry.phone ?? ''} onChange={(e) => onChange({ phone: e.target.value })} required />
                </Field>
                <Field label="WhatsApp Number">
                    <Input value={entry.whatsapp_number ?? ''} onChange={(e) => onChange({ whatsapp_number: e.target.value })} />
                </Field>

                {entry.can_login && (
                    <Field label="Email" required error={getError('email')}>
                        <Input
                            type="email"
                            value={entry.email ?? ''}
                            onChange={(e) => onChange({ email: e.target.value })}
                            required
                        />
                    </Field>
                )}

                <Field label="Occupation">
                    <Input value={entry.occupation ?? ''} onChange={(e) => onChange({ occupation: e.target.value })} />
                </Field>
                <Field label="Employer">
                    <Input value={entry.employer_name ?? ''} onChange={(e) => onChange({ employer_name: e.target.value })} />
                </Field>

                <Field label="Marital Status">
                    <Select
                        value={entry.marital_status ?? ''}
                        onValueChange={(v) => onChange({ marital_status: v })}
                    >
                        <SelectTrigger><SelectValue placeholder="Select marital status" /></SelectTrigger>
                        <SelectContent>
                            {resources.marital_statuses.map((m) => (
                                <SelectItem key={m.value} value={m.value}>{m.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field label="Emergency Contact">
                    <Input value={entry.emergency_contact ?? ''} onChange={(e) => onChange({ emergency_contact: e.target.value })} />
                </Field>

                <Field label="City">
                    <Input value={entry.city ?? ''} onChange={(e) => onChange({ city: e.target.value })} />
                </Field>
                <Field label="State">
                    <Input value={entry.state ?? ''} onChange={(e) => onChange({ state: e.target.value })} />
                </Field>
                <Field label="Country">
                    <Input value={entry.country ?? ''} onChange={(e) => onChange({ country: e.target.value })} />
                </Field>
                <Field label="Postal Code">
                    <Input value={entry.postal_code ?? ''} onChange={(e) => onChange({ postal_code: e.target.value })} />
                </Field>

                <Field label="ID Type">
                    <Select value={entry.id_type ?? ''} onValueChange={(v) => onChange({ id_type: v })}>
                        <SelectTrigger><SelectValue placeholder="Select ID type" /></SelectTrigger>
                        <SelectContent>
                            {resources.id_types.map((t) => (
                                <SelectItem key={t.value} value={t.value}>{t.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field label="ID Number">
                    <Input value={entry.id_number ?? ''} onChange={(e) => onChange({ id_number: e.target.value })} />
                </Field>
                <Field label="ID Expiry Date">
                    <Input
                        type="date"
                        value={entry.id_expiry_date ?? ''}
                        onChange={(e) => onChange({ id_expiry_date: e.target.value })}
                    />
                </Field>
            </div>
        </div>
    );
}

function Field({
    label,
    required,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <Label>
                {label}
                {required && <span className="text-destructive ml-0.5">*</span>}
            </Label>
            {children}
            {error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}
