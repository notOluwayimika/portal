import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { forStudent } from '@/actions/App/Finance/Http/Controllers/InvoiceController';
import { Can } from '@/components/can';
import { IssueCreditNoteModal } from '@/components/finance/issue-credit-note-modal';
import { RecordPaymentModal } from '@/components/finance/record-payment-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatNaira } from '@/lib/format';
import type { Invoice, Statement } from '@/types/finance';

type Props = {
    student: { uuid: string; name: string };
};

/**
 * The bursar statement — a PURE CONSUMER of GET /api/v1/finance/students/{uuid}/invoices,
 * bound to the exact shapes the acceptance harness proves. It NEVER nets or computes: the
 * invoice shows its full amount, each credit note is its own document, and the account
 * balance / available credit come straight from the API. Every money value is displayed
 * via formatNaira; there is no client-side money arithmetic (the API returns every figure
 * already computed).
 *
 * NOTE: the statement endpoint exposes invoices + credit notes + the account position, but
 * NOT a payments list (there is no such read endpoint). The account position reflects
 * payments' net effect; a per-payment history would need a new API — reported, not added.
 */
export default function FinanceStatement({ student }: Props) {
    const [statement, setStatement] = useState<Statement | null>(null);
    const [loading, setLoading] = useState(true);
    const [payFor, setPayFor] = useState<Invoice | null>(null);
    const [creditFor, setCreditFor] = useState<Invoice | null>(null);

    const load = useCallback(async () => {
        setLoading(true);

        try {
            const { data } = await axios.get<Statement>(
                forStudent.url(student.uuid),
            );
            setStatement(data);
        } catch {
            toast.error('Could not load the statement.');
        } finally {
            setLoading(false);
        }
    }, [student.uuid]);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const account = statement?.account;

    return (
        <>
            <Head title={`Statement — ${student.name}`} />

            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Finance statement</h1>
                    <p className="text-sm text-muted-foreground">
                        {student.name}
                    </p>
                </div>

                {/* Account position — where credit-note credit is visible. Straight from the
                    API; balance is signed (positive = owed). No derivation. */}
                {account && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Card className="p-4">
                            <p className="text-xs text-muted-foreground">
                                Account balance
                            </p>
                            <p className="text-lg font-semibold">
                                {formatNaira(account.balance)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Positive means the student owes this.
                            </p>
                        </Card>
                        <Card className="p-4">
                            <p className="text-xs text-muted-foreground">
                                Available credit
                            </p>
                            <p className="text-lg font-semibold">
                                {formatNaira(account.available_credit)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Carries forward to the next invoice.
                            </p>
                        </Card>
                    </div>
                )}

                {loading && (
                    <p className="text-sm text-muted-foreground">Loading…</p>
                )}

                {/* Invoices — each at its FULL amount + status; actions per row. */}
                {statement && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">Invoices</h2>
                        {statement.invoices.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No invoices.
                            </p>
                        )}
                        {statement.invoices.map((invoice) => (
                            <Card
                                key={invoice.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-3"
                            >
                                <div>
                                    <p className="font-medium">
                                        {invoice.display_number}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {invoice.academic_context}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <Badge
                                        variant={
                                            invoice.status === 'void'
                                                ? 'outline'
                                                : 'secondary'
                                        }
                                    >
                                        {invoice.status}
                                    </Badge>
                                    <span className="font-semibold">
                                        {formatNaira(invoice.total)}
                                    </span>
                                    {invoice.status !== 'void' && (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setPayFor(invoice)
                                                }
                                            >
                                                Record payment
                                            </Button>
                                            <Can permission="finance.credit-note.issue">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setCreditFor(invoice)
                                                    }
                                                >
                                                    Issue credit note
                                                </Button>
                                            </Can>
                                        </>
                                    )}
                                </div>
                            </Card>
                        ))}
                    </section>
                )}

                {/* Credit notes — each its OWN document beside the invoices, never netted in. */}
                {statement && statement.credit_notes.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">Credit notes</h2>
                        {statement.credit_notes.map((credit) => (
                            <Card
                                key={credit.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-3"
                            >
                                <div>
                                    <p className="font-medium">
                                        {credit.display_number}
                                    </p>
                                    {credit.note && (
                                        <p className="text-xs text-muted-foreground">
                                            {credit.note}
                                        </p>
                                    )}
                                </div>
                                <div className="flex items-center gap-3">
                                    <Badge variant="outline">
                                        {credit.kind === 'write_off'
                                            ? 'write-off'
                                            : 'credit note'}
                                    </Badge>
                                    <span className="font-semibold">
                                        {formatNaira(credit.amount)}
                                    </span>
                                </div>
                            </Card>
                        ))}
                    </section>
                )}
            </div>

            <RecordPaymentModal
                isOpen={payFor !== null}
                onClose={() => setPayFor(null)}
                invoice={payFor}
                onRecorded={() => void load()}
            />
            <IssueCreditNoteModal
                isOpen={creditFor !== null}
                onClose={() => setCreditFor(null)}
                invoice={creditFor}
                onIssued={() => void load()}
            />
        </>
    );
}

FinanceStatement.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/dashboard' },
    ],
};
