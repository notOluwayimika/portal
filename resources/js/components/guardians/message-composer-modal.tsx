import axios from 'axios';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';

interface MessageComposerModalProps {
    isOpen: boolean;
    onClose: () => void;
    guardianIds: number[];
    onSent: () => void;
}

export function MessageComposerModal({ isOpen, onClose, guardianIds, onSent }: MessageComposerModalProps) {
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleSend = async () => {
        if (!subject.trim() || !body.trim()) {
            setError('Subject and message body are required.');
            return;
        }
        setSubmitting(true);
        setError(null);
        try {
            await axios.post('/api/guardians/bulk-message', {
                guardian_ids: guardianIds,
                subject,
                body,
                channels: ['mail'],
            });
            setSubject('');
            setBody('');
            onSent();
            onClose();
        } catch {
            setError('Failed to send message. Please try again.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Send Announcement" size="lg">
            <div className="space-y-4">
                <div>
                    <Label htmlFor="msg-subject">Subject</Label>
                    <Input
                        id="msg-subject"
                        value={subject}
                        onChange={(e) => setSubject(e.target.value)}
                        placeholder="e.g. Parent-Teacher Meeting"
                    />
                </div>
                <div>
                    <Label htmlFor="msg-body">Message</Label>
                    <textarea
                        id="msg-body"
                        className="mt-1 w-full rounded-md border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                        rows={6}
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        placeholder="Type your message here…"
                    />
                </div>

                <div className="space-y-2">
                    <p className="text-xs font-medium text-muted-foreground">Channels</p>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked readOnly className="h-4 w-4" />
                        Email ({guardianIds.length} recipients)
                    </label>
                    <label className="flex cursor-not-allowed items-center gap-2 text-sm text-muted-foreground">
                        <input type="checkbox" disabled className="h-4 w-4" />
                        SMS <span className="text-xs">(coming soon)</span>
                    </label>
                    <label className="flex cursor-not-allowed items-center gap-2 text-sm text-muted-foreground">
                        <input type="checkbox" disabled className="h-4 w-4" />
                        WhatsApp <span className="text-xs">(coming soon)</span>
                    </label>
                </div>

                {error && <p className="text-sm text-destructive">{error}</p>}

                <div className="flex justify-end gap-2 pt-2">
                    <Button variant="outline" onClick={onClose} disabled={submitting}>Cancel</Button>
                    <Button onClick={handleSend} disabled={submitting}>
                        {submitting ? 'Sending…' : 'Send'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
