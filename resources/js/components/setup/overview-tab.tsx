import {
    BookOpen,
    Building2,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    GraduationCap,
    Layers,
    LayoutGrid,
    Target,
    Users,
    UserCheck,
    XCircle,
    AlertCircle,
    School,
    Shapes,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import type { SetupData } from '@/types/models';

/* ─── helpers ───────────────────────────────────────────────────────────────── */

function fmt(d: string | null | undefined): string {
    if (!d) return '—';
    return new Date(d).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function ordinal(n: number): string {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return n + (s[(v - 20) % 10] ?? s[v] ?? s[0]);
}

/* ─── sub-components ────────────────────────────────────────────────────────── */

function StatCard({
    label,
    value,
    icon: Icon,
    tone = 'neutral',
}: {
    label: string;
    value: number | string;
    icon: React.ElementType;
    tone?: 'neutral' | 'indigo' | 'emerald' | 'amber' | 'violet';
}) {
    const styles = {
        neutral: { bg: 'bg-slate-50 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700', icon: 'text-slate-500' },
        indigo:  { bg: 'bg-indigo-50 ring-indigo-100 dark:bg-indigo-950/40 dark:ring-indigo-900', icon: 'text-indigo-600 dark:text-indigo-400' },
        emerald: { bg: 'bg-emerald-50 ring-emerald-100 dark:bg-emerald-950/40 dark:ring-emerald-900', icon: 'text-emerald-600 dark:text-emerald-400' },
        amber:   { bg: 'bg-amber-50 ring-amber-100 dark:bg-amber-950/40 dark:ring-amber-900', icon: 'text-amber-600 dark:text-amber-400' },
        violet:  { bg: 'bg-violet-50 ring-violet-100 dark:bg-violet-950/40 dark:ring-violet-900', icon: 'text-violet-600 dark:text-violet-400' },
    }[tone];

    return (
        <div className="flex items-center gap-3 rounded-xl bg-white p-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-slate-900/40">
            <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ring-1 ${styles.bg}`}>
                <Icon className={`h-5 w-5 ${styles.icon}`} aria-hidden />
            </span>
            <span className="min-w-0">
                <span className="block text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{value}</span>
                <span className="text-xs text-muted-foreground">{label}</span>
            </span>
        </div>
    );
}

function SectionLabel({ icon: Icon, children }: { icon: React.ElementType; children: React.ReactNode }) {
    return (
        <div className="flex items-center gap-2 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
            <Icon className="h-3.5 w-3.5" />
            {children}
        </div>
    );
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between py-2.5 text-sm">
            <span className="text-slate-500">{label}</span>
            <span className="font-semibold text-slate-800 dark:text-slate-100">{value}</span>
        </div>
    );
}

interface CheckItem {
    label: string;
    count: number;
    tab: string;
}

function CheckRow({ item }: { item: CheckItem }) {
    const done = item.count > 0;
    return (
        <div className="flex items-center gap-3 rounded-lg px-3 py-2.5 transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
            {done
                ? <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-500" />
                : <XCircle className="h-4 w-4 shrink-0 text-slate-300" />
            }
            <span className="flex-1 text-sm font-medium text-slate-700 dark:text-slate-200">{item.label}</span>
            <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold tabular-nums ${
                done
                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                    : 'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500'
            }`}>
                {item.count}
            </span>
        </div>
    );
}

/* ─── main component ────────────────────────────────────────────────────────── */

export function OverviewTab({ data }: { data: SetupData | null }) {
    const session = data?.current_session;
    const term    = data?.current_term;

    const checklist: CheckItem[] = [
        { label: 'Academic Sessions',  count: data?.sessions          ?? 0, tab: 'sessions'   },
        { label: 'Class Levels',       count: data?.class_levels      ?? 0, tab: 'structure'  },
        { label: 'Arms',               count: data?.arms              ?? 0, tab: 'structure'  },
        { label: 'Class Combinations', count: data?.class_level_arms  ?? 0, tab: 'stream'     },
        { label: 'Subjects',           count: data?.subjects          ?? 0, tab: 'subjects'   },
        { label: 'Exam Types',         count: data?.exam_types        ?? 0, tab: 'exam-types' },
        { label: 'Grade Boundaries',   count: data?.grade_boundaries  ?? 0, tab: 'grades'     },
        { label: 'Curricula',          count: data?.curricula         ?? 0, tab: 'curricula'  },
    ];

    const done       = checklist.filter((c) => c.count > 0).length;
    const total      = checklist.length;
    const pct        = Math.round((done / total) * 100);
    const incomplete = checklist.filter((c) => c.count === 0).length;

    const termStatus = term?.status === 'active' ? 'active' : term?.status ?? null;

    return (
        <div className="space-y-5">

            {/* ── School Hero ──────────────────────────────────────────────── */}
            <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-5 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-4">
                        <div className="relative shrink-0">
                            <div className="absolute -inset-0.5 rounded-xl bg-gradient-to-tr from-indigo-500 to-violet-500 opacity-10 blur" />
                            <div className="relative flex size-14 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-indigo-100 dark:from-indigo-950/50 dark:to-violet-950/50 dark:ring-indigo-900">
                                <School className="h-7 w-7 text-indigo-600" />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                {data?.school?.name ?? '—'}
                            </h1>
                            <div className="flex flex-wrap items-center gap-2">
                                {session ? (
                                    <Badge className="rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-semibold text-emerald-700 shadow-sm dark:bg-emerald-900/30 dark:text-emerald-400">
                                        ● {session.name}
                                    </Badge>
                                ) : (
                                    <Badge className="rounded-full bg-amber-50 px-2.5 py-0.5 text-[10px] font-semibold text-amber-700 shadow-sm dark:bg-amber-900/30 dark:text-amber-400">
                                        ⚠ No active session
                                    </Badge>
                                )}
                                {term && (
                                    <Badge className="rounded-full bg-indigo-50 px-2.5 py-0.5 text-[10px] font-semibold text-indigo-700 shadow-sm dark:bg-indigo-900/30 dark:text-indigo-400">
                                        {ordinal(term.order)} Term
                                    </Badge>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Setup health */}
                    <div className="flex shrink-0 flex-col items-end gap-1">
                        <div className="flex items-center gap-2">
                            <span className="text-sm font-semibold text-slate-700 dark:text-slate-200">{pct}% configured</span>
                            {incomplete > 0
                                ? <AlertCircle className="h-4 w-4 text-amber-500" />
                                : <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                            }
                        </div>
                        <div className="h-2 w-40 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div
                                className="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all"
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                        <span className="text-[10px] text-slate-400">
                            {done}/{total} areas set up
                        </span>
                    </div>
                </div>
            </div>

            {/* ── People stats ─────────────────────────────────────────────── */}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="Students"  value={data?.students  ?? '—'} icon={GraduationCap} tone="indigo"  />
                <StatCard label="Teachers"  value={data?.teachers  ?? '—'} icon={UserCheck}     tone="emerald" />
                <StatCard label="Guardians" value={data?.guardians ?? '—'} icon={Users}         tone="violet"  />
                <StatCard label="Curricula" value={data?.curricula ?? '—'} icon={ClipboardList}  tone="amber"   />
            </div>

            {/* ── Two-column detail ─────────────────────────────────────────── */}
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">

                {/* Current Session — 2/5 */}
                <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)] lg:col-span-2">
                    <CardHeader className="flex flex-row items-center gap-2.5 border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                        <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                            <CalendarDays className="h-4 w-4 text-indigo-600" />
                        </div>
                        <CardTitle className="text-sm font-bold text-slate-800 dark:text-slate-100">
                            Current Session
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-5">
                        {session ? (
                            <div className="space-y-1 divide-y divide-slate-50 dark:divide-slate-800">
                                <InfoRow label="Session" value={session.name} />
                                <InfoRow label="Terms configured" value={data?.terms_in_session ?? '—'} />
                                {term ? (
                                    <>
                                        <div className="pt-3">
                                            <SectionLabel icon={Target}>Active Term</SectionLabel>
                                        </div>
                                        <InfoRow label="Term" value={term.name} />
                                        <InfoRow
                                            label="Status"
                                            value={
                                                <Badge className={`rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize ${
                                                    termStatus === 'active'
                                                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                                        : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                                                }`}>
                                                    {termStatus}
                                                </Badge>
                                            }
                                        />
                                        <InfoRow label="Start date" value={fmt(term.start_date)} />
                                        <InfoRow label="End date"   value={fmt(term.end_date)} />
                                    </>
                                ) : (
                                    <div className="pt-4 text-center text-xs text-slate-400">No active term</div>
                                )}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center gap-2 py-8 text-center">
                                <CalendarDays className="h-8 w-8 text-slate-200" />
                                <p className="text-sm font-medium text-slate-500">No active session</p>
                                <p className="text-xs text-slate-400">Create a session in the Sessions tab.</p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Academic Structure — 3/5 */}
                <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)] lg:col-span-3">
                    <CardHeader className="flex flex-row items-center gap-2.5 border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                        <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                            <Layers className="h-4 w-4 text-indigo-600" />
                        </div>
                        <CardTitle className="text-sm font-bold text-slate-800 dark:text-slate-100">
                            Academic Structure
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-5">
                        <div className="grid grid-cols-2 gap-x-8 divide-y divide-slate-50 dark:divide-slate-800 sm:grid-cols-3 sm:divide-y-0">
                            {[
                                { label: 'Sessions',           value: data?.sessions          ?? '—', icon: CalendarDays },
                                { label: 'Class Levels',       value: data?.class_levels      ?? '—', icon: Building2    },
                                { label: 'Arms',               value: data?.arms              ?? '—', icon: Shapes       },
                                { label: 'Class Combos',       value: data?.class_level_arms  ?? '—', icon: LayoutGrid   },
                                { label: 'Subjects',           value: data?.subjects          ?? '—', icon: BookOpen     },
                                { label: 'Exam Types',         value: data?.exam_types        ?? '—', icon: ClipboardList },
                                { label: 'Grade Boundaries',   value: data?.grade_boundaries  ?? '—', icon: Target       },
                            ].map((row) => (
                                <div key={row.label} className="flex items-center justify-between py-3 sm:flex-col sm:items-start sm:gap-1">
                                    <div className="flex items-center gap-1.5 text-xs text-slate-500">
                                        <row.icon className="h-3.5 w-3.5 shrink-0" />
                                        {row.label}
                                    </div>
                                    <span className="text-lg font-bold tabular-nums text-slate-900 dark:text-white sm:text-2xl">{row.value}</span>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* ── Setup Checklist ───────────────────────────────────────────── */}
            <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                <CardHeader className="flex flex-row items-center justify-between border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                    <div className="flex items-center gap-2.5">
                        <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                            <CheckCircle2 className="h-4 w-4 text-indigo-600" />
                        </div>
                        <CardTitle className="text-sm font-bold text-slate-800 dark:text-slate-100">
                            Setup Checklist
                        </CardTitle>
                    </div>
                    <span className={`rounded-full px-2.5 py-0.5 text-[10px] font-semibold ${
                        incomplete === 0
                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                            : 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                    }`}>
                        {incomplete === 0 ? 'All done' : `${incomplete} remaining`}
                    </span>
                </CardHeader>
                <CardContent className="p-3">
                    <div className="grid grid-cols-1 gap-0.5 sm:grid-cols-2 lg:grid-cols-4">
                        {checklist.map((item) => (
                            <CheckRow key={item.label} item={item} />
                        ))}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
