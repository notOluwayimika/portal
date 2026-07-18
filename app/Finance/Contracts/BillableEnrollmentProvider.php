<?php

namespace App\Finance\Contracts;

/**
 * The ACL port (driven/secondary port) Finance owns to read an enrollment without
 * touching Academics' models or tables. The implementation lives OUTSIDE Finance
 * (App\Academics\BillableEnrollmentAdapter) and is bound in the composition root,
 * so the dependency arrow points Academics → Finance's contract, never the reverse
 * (Module Blueprint: "coupling flows one way; the reactor depends on the published
 * fact"). Here Finance is the consumer, so it publishes the interface and Academics
 * adapts to it.
 *
 * Returns null when no billable enrollment matches in the active School — the
 * SchoolScope on the underlying academic model already constrains visibility, so
 * cross-School lookups return null by construction.
 */
interface BillableEnrollmentProvider
{
    public function findByUuid(string $enrollmentUuid): ?BillableEnrollment;
}
