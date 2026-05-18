import { Download, Mail, Power, PowerOff } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface BulkActionBarProps {
    count: number;
    totalMatching: number;
    selectAllMatching: boolean;
    onSelectAllMatching: () => void;
    onClearSelection: () => void;
    onMessage: () => void;
    onExport: () => void;
    onEnableLogin: () => void;
    onDisableLogin: () => void;
    onChangeStatus: (status: string) => void;
}

export function BulkActionBar({
    count, totalMatching, selectAllMatching,
    onSelectAllMatching, onClearSelection,
    onMessage, onExport, onEnableLogin, onDisableLogin, onChangeStatus,
}: BulkActionBarProps) {
    if (count === 0) return null;

    const confirm = (msg: string, fn: () => void) => {
        if (window.confirm(msg)) fn();
    };

    return (
        <div className="fixed bottom-0 left-0 right-0 z-40 border-t bg-background/95 px-6 py-3 shadow-lg backdrop-blur">
            <div className="flex flex-wrap items-center gap-3">
                <span className="text-sm font-medium">{count} selected</span>

                {!selectAllMatching && count < totalMatching && (
                    <button
                        className="text-xs text-primary underline-offset-2 hover:underline"
                        onClick={onSelectAllMatching}
                    >
                        Select all {totalMatching} matching →
                    </button>
                )}

                {selectAllMatching && (
                    <span className="text-xs text-muted-foreground">All {totalMatching} matching selected</span>
                )}

                <button
                    className="text-xs text-muted-foreground underline-offset-2 hover:underline"
                    onClick={onClearSelection}
                >
                    Clear
                </button>

                <div className="ml-auto flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" onClick={onMessage}>
                        <Mail className="mr-1.5 h-3.5 w-3.5" />
                        Message
                    </Button>

                    <Button variant="outline" size="sm" onClick={onExport}>
                        <Download className="mr-1.5 h-3.5 w-3.5" />
                        Export
                    </Button>

                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => confirm(`Enable login for ${count} guardian(s)?`, onEnableLogin)}
                    >
                        <Power className="mr-1.5 h-3.5 w-3.5" />
                        Enable Login
                    </Button>

                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => confirm(`Disable login for ${count} guardian(s)?`, onDisableLogin)}
                    >
                        <PowerOff className="mr-1.5 h-3.5 w-3.5" />
                        Disable Login
                    </Button>

                    <select
                        className="h-8 rounded-md border bg-background px-3 text-xs"
                        defaultValue=""
                        onChange={(e) => {
                            if (e.target.value && confirm(`Set status to "${e.target.value}" for ${count} guardian(s)?`, () => onChangeStatus(e.target.value))) {
                                e.target.value = '';
                            }
                        }}
                    >
                        <option value="" disabled>Set Status…</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="blocked">Blocked</option>
                    </select>
                </div>
            </div>
        </div>
    );
}
