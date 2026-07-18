<?php

namespace App\Finance\Contracts;

/**
 * The enrollment fact Finance needs to raise an invoice — in FINANCE's language,
 * not Academics'. This is the DTO half of the ACL port (§8/§9 Module Blueprint):
 * Finance owns the shape; an adapter OUTSIDE Finance (App\Academics) builds it by
 * reading the academic model. Finance domain code never sees StudentCurriculum.
 *
 * Everything here is either a durable IDENTITY (used as a live FK — the enrollment
 * is durable since the withdraw soft-end slice) or a SNAPSHOT captured at billing
 * time (copied onto the invoice, never re-joined — docs/finance-data-ownership.md
 * Part 3: a historical statement must never re-render with today's academic data).
 *
 * It is immutable and carries no Eloquent — crossing the module boundary as a
 * plain value is the whole point (Engineering Invariant 4).
 */
final readonly class BillableEnrollment
{
    public function __construct(
        // Durable identities (live FKs on the invoice).
        public int $enrollmentId,
        public string $enrollmentUuid,
        public int $studentId,
        public int $schoolId,
        // Snapshots (copied onto the invoice at billing time — never re-joined).
        public string $studentName,
        public string $academicContext,
    ) {}
}
