<?php

namespace App\Finance\Enums;

/**
 * What an invoice IS — the two reasons a School raises one.
 *
 * `scheduled` is the term bill: the enrollment episode's fees, raised from the fee
 * schedule. It is the value every invoice written before this enum existed carries,
 * and the backfill in 2026_08_18_100000 says so explicitly rather than by default.
 *
 * `supplementary` is everything raised OUTSIDE the schedule against an episode that
 * is already billed — a damaged appliance, a trip, a lost book, a late fee. Before
 * this enum the only way to bill one was to VOID the term invoice and re-issue it,
 * which discards every payment allocation already made against it.
 *
 * THE DISTINCTION IS LOAD-BEARING AT THE DATABASE, NOT ONLY IN THE UBIQUITOUS
 * LANGUAGE. "At most one ACTIVE invoice per enrollment episode" is a set-based
 * invariant enforced by UNIQUE(school_id, active_enrollment_key) over a generated
 * column (2026_07_19_120000). That invariant is TRUE of the term bill — an episode
 * has one term bill or it is double-billed — and FALSE of supplementary charges,
 * which are unbounded in number by their nature. So the generated key now keys on
 * `status = 'issued' AND kind = 'scheduled'`: a supplementary invoice computes a
 * NULL key, NULLs do not collide in a MySQL unique index, and any number of them
 * coexist with the one scheduled invoice.
 *
 * WHICH MAKES `kind` IMMUTABLE, NECESSARILY. If a scheduled invoice could be
 * flipped to supplementary its key would recompute to NULL, freeing the episode's
 * slot for a SECOND scheduled invoice while the first is still issued and still
 * collecting payments — the double-billing the guard exists to prevent, reached
 * through the one UPDATE path finance_invoices legitimately allows (issued → void).
 * `finance_invoices_kind_immutable` closes that at the DB; see the migration.
 *
 * DELIBERATELY NOT A STATUS. Kind answers "what is this bill for", status answers
 * "is it live". They are orthogonal: a supplementary invoice voids exactly as a
 * scheduled one does, and the generated key reads BOTH because the invariant is
 * about live term bills specifically.
 */
enum InvoiceKind: string
{
    case Scheduled = 'scheduled';
    case Supplementary = 'supplementary';

    /**
     * Only the scheduled invoice is constrained to one-per-episode.
     *
     * Read by GenerateInvoice to decide whether the friendly 422 pre-check and the
     * duplicate-key translation apply at all. It is a DERIVED convenience, not a
     * second source of truth: the authority is the generated column's expression in
     * 2026_08_18_100000, and this method exists so the Action does not spell the
     * same predicate a second time as a bare `=== self::Scheduled`.
     */
    public function isEpisodeExclusive(): bool
    {
        return $this === self::Scheduled;
    }
}
