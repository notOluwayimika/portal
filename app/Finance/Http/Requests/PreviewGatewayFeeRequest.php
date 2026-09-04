<?php

namespace App\Finance\Http\Requests;

/**
 * Who may preview a fee, and it is exactly who may pay.
 *
 * EXTENDS RATHER THAN RESTATES, and that is the security property. `InitiateGatewayPaymentRequest`
 * resolves the invoice by uuid WITHIN `SchoolScope` and authorises with
 * `GuardianPaymentAuthorisation::mayPay()` — so a uuid the caller has no business seeing resolves to
 * null and is refused as UNAUTHORISED rather than not-found, because which of the two it is would
 * itself leak whether the uuid exists.
 *
 * A preview endpoint that resolved the id itself would be an enumeration surface: hand it a uuid and
 * it answers with a number. Inheriting the scoping means the preview cannot drift from the path that
 * will charge them, because it IS that path's rule.
 *
 * NOTHING IS ADDED HERE. The rules, the authorisation and the resolution are all the parent's. This
 * class exists so the route binds to a distinct type and so the reason above is written down at the
 * boundary it protects, not so it can diverge.
 */
final class PreviewGatewayFeeRequest extends InitiateGatewayPaymentRequest {}
