import { ActivityTimeline } from '@/components/activity-logs/activity-timeline';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import axios from 'axios';
import { useEffect, useState } from 'react';

interface SubjectHistoryDrawerProps {
    isOpen: boolean;
    enrollmentId: string;
    studentId: string;
    onClose: () => void;
}

type FilterType = 'all' | 'added' | 'dropped' | 'restored';

const FILTERS: { label: string; value: FilterType }[] = [
    { label: 'All', value: 'all' },
    { label: 'Added', value: 'added' },
    { label: 'Dropped', value: 'dropped' },
    { label: 'Restored', value: 'restored' },
];

export function SubjectHistoryDrawer({ isOpen, enrollmentId, studentId, onClose }: SubjectHistoryDrawerProps) {
    const [items, setItems] = useState<any[]>([]);
    const [filter, setFilter] = useState<FilterType>('all');
    const [loading, setLoading] = useState(false);
    const [selectedId, setSelectedId] = useState<number | null>(null);

    useEffect(() => {
        if (!isOpen) return;

        setLoading(true);
        axios
            .get(`/api/students/${studentId}/enrollments/${enrollmentId}/subjects/history`)
            .then((res) => setItems(res.data?.data ?? []))
            .catch(() => setItems([]))
            .finally(() => setLoading(false));
    }, [isOpen, enrollmentId, studentId]);

    const filtered = items.filter((item) => {
        if (filter === 'all') return true;
        const desc: string = (item.description ?? '').toLowerCase();
        if (filter === 'added') return desc.includes('added');
        if (filter === 'dropped') return desc.includes('dropped');
        if (filter === 'restored') return desc.includes('restored');
        return true;
    });

    return (
        <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full sm:max-w-lg" side="right">
                <SheetHeader className="border-b pb-3">
                    <SheetTitle>Subject History</SheetTitle>
                </SheetHeader>

                <div className="flex gap-1 border-b py-2">
                    {FILTERS.map((f) => (
                        <Button
                            key={f.value}
                            variant={filter === f.value ? 'secondary' : 'ghost'}
                            size="sm"
                            className="h-7 text-xs"
                            onClick={() => setFilter(f.value)}
                        >
                            {f.label}
                        </Button>
                    ))}
                </div>

                <div className="flex-1 overflow-y-auto py-4">
                    {loading ? (
                        <p className="py-8 text-center text-sm text-slate-400">Loading history…</p>
                    ) : filtered.length === 0 ? (
                        <p className="py-8 text-center text-sm text-slate-400">No history entries.</p>
                    ) : (
                        <ActivityTimeline items={filtered} onOpen={(id) => setSelectedId(id)} />
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
