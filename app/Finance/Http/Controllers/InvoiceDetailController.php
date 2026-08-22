<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Http\Resources\InvoiceResource;
use App\Finance\Models\Invoice;
use App\Finance\Models\VoidRequest;
use App\Finance\Services\InvoiceReadModel;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * U7 — ONE INVOICE. The detail screen, and the printable document beside it.
 *
 * ── WHY PROPS AND NOT A JSON ENDPOINT ──
 *
 * The same four reasons PaymentReceiptController records for the receipt, and the fourth is the
 * one that decided it: there is no second consumer. A `GET /v1/finance/invoices/{uuid}` with one
 * caller that is a page is the primitive built ahead of its consumer, and it would put a SECOND
 * isolation path and a second 404 on a row the page shell has already resolved. The statement page
 * (routes/web.php) and the receipt are the two in-repo shapes for a finance screen that resolves
 * its own props server-side; this follows them.
 *
 * IT SERIALISES THROUGH InvoiceResource, which is the point rather than a convenience. That
 * resource is the ONLY invoice serialiser in the codebase — both generate 201s and the
 * per-student read answer through it — and the supplementary-invoice ticket's whole finding was
 * that a screen cannot show what the serialiser drops. A second, page-shaped invoice payload
 * hand-built here would be a second place for `kind` to go missing.
 *
 * ── THE PERMISSION IS `finance.access`, READ OFF THE SIBLINGS ──
 *
 * Neither route carries an ability of its own, so both take the group's `finance.access`. That is
 * what the statement page carries, what the receipt carries, and what the statement's own feed
 * `GET /v1/finance/students/{student:uuid}/invoices` carries — and this page shows strictly LESS
 * than that feed already returns for the same invoice. The ACTIONS on it are gated individually
 * and separately: `<Can>` on the button, the ability on the API route behind it, and the invoice's
 * own `can_*` flags deciding whether the operation is legal at all. Reading an invoice is not the
 * authority to void it.
 *
 * ── A VOIDED INVOICE OPENS. IT DOES NOT 404 ──
 *
 * `Invoice::scopeExcludingVoid()` is a NAMED scope and deliberately not a global one: a global
 * scope would make `{invoice:uuid}` binding miss a voided invoice and turn the double-void 422
 * into a 404 (the scope's own docblock, and the read route's comment in
 * routes/endpoints/finance.php). Nothing here re-imposes it. The void decision trail — when, why,
 * and the request that carried it — is what someone opening a voided invoice has come for, and the
 * page states the void rather than hiding the document.
 *
 * ── THE PRINTABLE VIEW REFUSES NOTHING, AND THAT IS A FINDING RATHER THAN AN OMISSION ──
 *
 * The receipt refuses to print a payment with `origin = 'migrated'`, because WCBS collected that
 * money and printing this system's receipt for it would be this system claiming an act it did not
 * perform. THAT PREDICATE HAS NO INVOICE-SIDE EQUIVALENT: `finance_invoices` carries no origin or
 * provenance column at all (the create migration and every alter since), and the opening-balance
 * import — the one cutover path that writes `origin = 'migrated'` anywhere — raises NO INVOICE by
 * rule (PostOpeningBalanceBatch step 3, R6: the import cannot choose the episode, the episode's
 * slot belongs to native billing, and the portal must not originate a document WCBS already
 * issued). So every row in this table was issued by this system, and a migrated refusal here would
 * be a branch matching zero rows now and forever.
 *
 * What an invoice DOES have is a void, and the printable view marks it rather than refusing it: a
 * voided invoice prints with the void stated on the document, because the reader who needs the
 * paper is the one reconciling why a charge is gone. Printing it silently as a demand for payment
 * is the failure to avoid; refusing to print it is not.
 */
class InvoiceDetailController extends Controller
{
    /**
     * The interactive screen. Symfony's Response type covers both of Inertia's answers (a
     * JsonResponse to an XHR visit, a view Response to a full load) — the receipt records the
     * same, and it matters here because the actions on this page navigate.
     */
    public function show(Request $request, Invoice $invoice, InvoiceReadModel $invoices): Response
    {
        // SchoolScope bounded the binding already: an invoice belonging to another School does not
        // resolve and produces the same 404 as a uuid that never existed. Nothing below re-checks
        // it, and nothing below should — a hand-written school_id predicate here would read as the
        // boundary and hide the fact that the model carries it.
        return Inertia::render('admin/finance/invoice', $this->props($request, $invoice, $invoices))
            ->toResponse($request);
    }

    /**
     * The printable document. A SEPARATE page from the screen above, following the receipt: a
     * document that prints the application's toolbar, tabs and action buttons is not printable,
     * and stripping them with print-only CSS on an interactive page means every future control
     * added to that page has to remember it exists. Two pages, one of which has no controls.
     */
    public function print(Request $request, Invoice $invoice, InvoiceReadModel $invoices): Response
    {
        $school = ActiveSchool::getOrFail();

        return Inertia::render('admin/finance/invoice-print', $this->props($request, $invoice, $invoices) + [
            'school' => [
                'name' => $school->name,
                'address' => $school->address,
                'phone' => $school->phone,
                'email' => $school->email,
            ],
        ])->toResponse($request);
    }

    /**
     * What both pages are built from. ONE resolution, so the screen and the paper cannot state
     * different things about one invoice — the failure mode of two hand-built payloads is that
     * they drift in the direction nobody is looking at, which for these two is the paper.
     *
     * @return array<string, mixed>
     */
    private function props(Request $request, Invoice $invoice, InvoiceReadModel $invoices): array
    {
        // forDetail(), NOT the bound model. InvoiceSettlement reads `allocated_minor` and
        // `approved_credit_minor` as plain attributes and treats an absent one as zero, so the
        // bound model — loaded by uuid with no aggregates — would serialise a fully-paid invoice
        // as unpaid, its full total outstanding, offering to void it. The read model owns that
        // expression for both callers; see its settlementSums().
        $loaded = $invoices->forDetail($invoice);

        // ONE read of the trail, used twice below. Two calls would be two identical queries on
        // every page load for one question.
        $voidRequests = $invoices->voidRequestsForInvoice($loaded);

        return [
            // FULLY RESOLVED TO THE WIRE SHAPE, deliberately, and this is not decoration.
            // `toArray()` alone leaves the nested pieces as OBJECTS — `lines` is an
            // AnonymousResourceCollection of InvoiceLineResource and every money value is a Money —
            // which encode correctly on the way to the browser and are opaque to anything that
            // reads the prop as an array on the way out. Encoding through the resource's own
            // JsonSerializable path produces EXACTLY the bytes
            // `GET /v1/finance/students/{uuid}/invoices` produces for this invoice: the page binds
            // to the same contract as the statement, and a test can assert `invoice.lines.0.*`
            // rather than asserting that a collection has a length. (`->response()->getData()`
            // would wrap it in `data`; resource wrapping is not disabled in this application.)
            'invoice' => json_decode((string) json_encode(new InvoiceResource($loaded)), true),
            // The STUDENT is read THROUGH the invoice, never passed alongside it. A student uuid in
            // the URL beside an invoice uuid would let a caller name an invoice and a statement that
            // do not belong together, and the page would then link "back" to a student the invoice
            // is not on — the reasoning the allocation route records for the same shape.
            'student' => [
                'uuid' => $loaded->student?->uuid,
                'name' => $loaded->billed_to_name,
            ],
            // WHEN IT WAS ISSUED — the document's own date, and the one thing a printed invoice
            // needs that InvoiceResource does not carry (it serialises `cancelled_at` and no
            // creation timestamp). Formatted in PHP for the reason below.
            'issued_at' => $loaded->created_at->format('j F Y'),
            // WHEN IT WAS VOIDED, FORMATTED HERE. bin/ci-money-lint.php's format ban is TOTAL
            // inside resources/js/pages/admin/finance/ — every number there is treated as money —
            // so `new Date(…).toLocaleString()` on these pages is a lint finding. The receipt
            // formats both of its dates in PHP for exactly this reason, and the reason is the right
            // one rather than the way round the rule: these are documents, and a document's own
            // rendering of a date belongs with the rest of its values. `cancelled_at` still travels
            // on InvoiceResource as ISO-8601 for machine consumers; this is the human form beside it.
            'voided_at' => $loaded->cancelled_at?->format('j F Y, H:i'),
            // The void trail. A PENDING request is why the page offers no "Request void" button —
            // the statement suppresses the same control for the same reason, so a maker cannot
            // stack two open requests — and a DECIDED one is the audit trail on a voided document.
            //
            // SHAPED HERE RATHER THAN THROUGH VoidRequestResource, and the reason is the dates
            // again: that resource answers the approvals QUEUE, where the client formats its own
            // timestamps and is allowed to, and it carries `can_approve`/`can_reject` — decision
            // authority this page has no control for and must not imply it has. What a document
            // needs is narrower and already rendered: who proposed it, why, when, and how it ended.
            'void_trail' => $voidRequests
                ->map(function (VoidRequest $vr): array {
                    // `instanceof User`, not `?->name` — the relation is not generically typed, so
                    // Larastan reads the related model as a bare Model and `->name` as undefined.
                    // VoidRequestResource narrows the same relation the same way.
                    $maker = $vr->submittedBy;

                    return [
                        'id' => $vr->uuid,
                        'status' => $vr->status->value,
                        'reason' => $vr->reason,
                        'submitted_by_name' => $maker instanceof User ? $maker->name : null,
                        'submitted_at' => $vr->created_at->format('j F Y, H:i'),
                        'decided_at' => $vr->decided_at?->format('j F Y, H:i'),
                        'rejection_reason' => $vr->rejection_reason,
                    ];
                })
                ->values()
                ->all(),
            // DERIVED SERVER-SIDE, like every other eligibility on this page. The statement computes
            // its equivalent in the browser by matching rendered invoice numbers against pending
            // requests; here the invoice row is in hand, so the question is asked of the database.
            'has_pending_void' => $voidRequests
                ->contains(fn (VoidRequest $vr) => $vr->status === VoidRequestStatus::Submitted),
        ];
    }
}
