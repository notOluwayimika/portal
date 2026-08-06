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
        /*
         * PRICING COORDINATES — the (term, class level) pair a fee schedule is keyed to
         * ({@see \App\Finance\Services\FeeScheduleLookup::activeFor()}). Added with their first
         * consumer, the opening-balance validator's §5 comparison, which cannot reach a price
         * from a student without them; V2's bulk generation needs the same two.
         *
         * These are IDENTITIES, not snapshots: they name catalog rows, they are not copied onto
         * an invoice document.
         *
         * NULLABLE, and that is the schema speaking rather than caution — `curricula.term_id`,
         * `curricula.class_level_arm_id` and `class_level_arms.class_level_id` are every one of
         * them nullable columns (verified against information_schema on 2026-08-06). A
         * non-nullable field here would be the adapter asserting something the database does not
         * guarantee, and the honest downstream behaviour for an enrollment that cannot name its
         * class level is "not comparable", which is a null.
         */
        public ?int $termId,
        public ?int $classLevelId,
    ) {}
}
