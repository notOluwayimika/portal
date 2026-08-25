<?php

namespace App\Services\Rollover;

/**
 * The one place a rollover batch name is written, and the one place it is matched.
 *
 * ── ONE DEFINITION, THREE READERS ────────────────────────────────────────────────────────────────
 * The name is written at dispatch, matched by the still-draining warning (`rollover:%:school:{id}:%`),
 * and will be matched again by slice 2's progress view reading `job_batches`. Three readers of a
 * string template is three chances to disagree about a colon.
 *
 * The drift is silent in the worst way: a progress view whose LIKE pattern no longer matches shows
 * "no batches running" — indistinguishable from a finished rollover — while jobs are mid-flight and
 * a registrar is being told it is safe to change the current session. Nothing errors, and the
 * screen is confidently wrong.
 *
 * So the template lives here, and both the writer and the matchers call it. Pinned by a test in
 * slice 1, before the second matcher exists, because a format is cheapest to fix while it has one
 * reader.
 */
final class RolloverBatchName
{
    public const KIND_END_OF_TERM = 'end-of-term';

    public const KIND_END_OF_YEAR = 'end-of-year';

    /**
     * `rollover:end-of-term:school:7:term:42`
     *
     * The scope segment differs by kind — a term rollover is scoped to a term, a year rollover to
     * the session being closed — which is why the suffix is a parameter rather than a fixed key.
     */
    public static function for(string $kind, int $schoolId, string $scopeKey, int $scopeId): string
    {
        return "rollover:{$kind}:school:{$schoolId}:{$scopeKey}:{$scopeId}";
    }

    public static function forTerm(int $schoolId, int $termId): string
    {
        return self::for(self::KIND_END_OF_TERM, $schoolId, 'term', $termId);
    }

    public static function forSession(int $schoolId, int $sessionId): string
    {
        return self::for(self::KIND_END_OF_YEAR, $schoolId, 'session', $sessionId);
    }

    /**
     * The LIKE pattern for every rollover batch of a school, either kind.
     *
     * Deliberately a sibling of the writer rather than a hand-written string at the query: the two
     * only stay compatible if they are edited together, and they are only edited together if they
     * live together.
     */
    public static function likeForSchool(int $schoolId): string
    {
        return "rollover:%:school:{$schoolId}:%";
    }
}
