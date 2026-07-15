import { Checkbox } from '@/components/ui/checkbox';
import type { SchoolOption } from '@/types';

/**
 * Checkbox list of schools used to manage school_user access grants.
 * Schools in `lockedUuids` (e.g. a teacher's home school) render checked
 * and disabled — access to them is implicit and cannot be revoked here.
 */
export function SchoolChecklist({
    schools,
    selected,
    toggle,
    lockedUuids = [],
}: {
    schools: SchoolOption[];
    selected: string[];
    toggle: (uuid: string) => void;
    lockedUuids?: string[];
}) {
    return (
        <div className="flex max-h-56 flex-col gap-2 overflow-y-auto rounded-md border p-3">
            {schools.map((school) => {
                const locked = lockedUuids.includes(school.uuid);

                return (
                    <label
                        key={school.uuid}
                        className="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            checked={locked || selected.includes(school.uuid)}
                            disabled={locked}
                            onCheckedChange={() => !locked && toggle(school.uuid)}
                        />
                        {school.name}
                        {locked && (
                            <span className="text-xs text-muted-foreground">
                                (Home)
                            </span>
                        )}
                    </label>
                );
            })}
            {schools.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No schools available. Create a school first.
                </p>
            )}
        </div>
    );
}
