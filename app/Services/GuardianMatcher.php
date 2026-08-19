<?php

namespace App\Services;

use App\Models\Guardian;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * THE ONE DEFINITION OF "the same person, already a guardian in this school".
 *
 * Extracted verbatim in behaviour from GuardianImportService::lookupExistingInDb,
 * which was the only correct implementation of it in the codebase: the spreadsheet
 * import deduped and both interactive create paths did not, which is precisely how a
 * school ended up with three rows for one mother. The extraction exists so there is
 * one rule and not three — a second hand-written "same person" predicate is drift
 * waiting for a deploy.
 *
 * WHY IT IS A SERVICE AND NOT A GuardianRepository METHOD. GuardianRepository already
 * carries four finders and every one of them is DELIBERATELY LOOSER than this: each
 * matches `guardians.school_id = X OR the guardian's user has access to X`
 * (`GuardianRepository.php:29-32`, `:52-55`), because they answer "can this actor
 * reach this guardian record". This class answers a different question — "is this
 * person ALREADY a guardian row owned by this school" — and its answer must be
 * strictly `guardians.school_id = X`, because its consumer is a WRITE that would
 * otherwise reuse another school's row. Two predicates that differ by an OR branch,
 * sitting as sibling methods on one repository, is the shape of the next defect.
 *
 * SCOPES ARE DROPPED AND BOTH PREDICATES PINNED, deliberately. Guardian's global
 * scope is `school_id = active OR the user has access to active`
 * (`Guardian::applySchoolScope`, `app/Models/Guardian.php:88-94`), so under the
 * default scope a multi-school parent's OTHER school's rows are visible and a match
 * here could return one of them. `withoutGlobalScopes()` also drops SoftDeletes, so
 * `deleted_at` is re-pinned by hand rather than inherited.
 */
class GuardianMatcher
{
    /**
     * The raw candidates, without adjudicating a conflict.
     *
     * Returned separately from findInSchool() so a READ surface (the duplicate-check
     * endpoint) can show the operator both candidates, while a WRITE surface still
     * refuses to guess between them.
     *
     * @return array{by_email: ?Guardian, by_phone: ?Guardian}
     */
    public function candidatesInSchool(?string $email, ?string $phone, ?string $whatsapp, int $schoolId): array
    {
        // Normalised on the way in, with the SAME call the write path makes
        // (GuardianService::createGuardianWithUser), so a match key and a stored
        // value can never disagree on format. The spreadsheet import already
        // normalised upstream in GuardianImportRowValidator (:260-276) before
        // calling the code this was extracted from, and PhoneNormalizer is
        // idempotent on its own output ('+234…' → digits → '+234…'), so doing it
        // here changes nothing for the import and fixes the interactive paths,
        // which normalised the STORED value and not the LOOKUP value.
        $email = $email !== null && $email !== '' ? Str::lower(trim($email)) : null;
        $phone = PhoneNormalizer::normalize($phone);
        $whatsapp = PhoneNormalizer::normalize($whatsapp);

        $byEmail = null;
        $byPhone = null;

        // Scoped to the target School: a Guardian is a per-School record (§6.2),
        // so a match in another School must NOT be reused here — it becomes a new
        // Guardian row sharing the same User.
        if ($email) {
            $byEmail = $this->baseQuery($schoolId)
                ->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))
                ->first();
        }

        if ($phone) {
            $byPhone = $this->baseQuery($schoolId)
                ->where(function ($q) use ($phone) {
                    $q->where('phone', $phone)->orWhere('whatsapp_number', $phone);
                })
                ->first();
        }

        // Whatsapp fallback only if phone didn't match anything.
        if (! $byPhone && $whatsapp) {
            $byPhone = $this->baseQuery($schoolId)
                ->where(function ($q) use ($whatsapp) {
                    $q->where('phone', $whatsapp)->orWhere('whatsapp_number', $whatsapp);
                })
                ->first();
        }

        return ['by_email' => $byEmail, 'by_phone' => $byPhone];
    }

    /**
     * The single match for this person in this school, or null.
     *
     * @throws ImportConflictException when email and phone match different guardians.
     *
     * The exception class keeps its import-era name so GuardianImportService's
     * existing catch (`GuardianImportService.php:77`) is untouched by the
     * extraction — renaming it would have been a behaviour change smuggled into a
     * refactor. It is now thrown from a non-import caller too, which makes the name
     * wrong; that is a rename for its own commit, recorded rather than done here.
     */
    public function findInSchool(?string $email, ?string $phone, ?string $whatsapp, int $schoolId): ?Guardian
    {
        ['by_email' => $byEmail, 'by_phone' => $byPhone] = $this->candidatesInSchool($email, $phone, $whatsapp, $schoolId);

        if ($byEmail && $byPhone && $byEmail->id !== $byPhone->id) {
            throw new ImportConflictException(sprintf(
                'Conflicting match: email belongs to %s, phone belongs to %s. Resolve manually.',
                $byEmail->full_name,
                $byPhone->full_name,
            ));
        }

        return $byEmail ?: $byPhone;
    }

    /**
     * Does a submitted email REFUTE a match that was made on the phone number?
     *
     * A phone match is weaker evidence of identity than the first cut of this change
     * assumed. A household shares one landline: `phone` matches the father's guardian
     * row while the operator is entering the mother, with her own address. Reusing
     * there is not a near-miss — it is the wrong person, and the reuse would then
     * attach her child to his record.
     *
     * An email that differs from the matched account's stored address settles it, and
     * treating it as decisive is the same principle findInSchool already applies when
     * email and phone point at two different guardians: identity evidence that
     * disagrees is not adjudicated by preference. Here it resolves in the safe
     * direction — do not reuse, create a new guardian.
     *
     * Returns FALSE when the match has no stored address. That is a different case and
     * not this method's to decide: nothing contradicts, so the caller must choose
     * between writing an identity key it was not asked to write and refusing.
     */
    public function emailRefutesMatch(Guardian $match, ?string $email): bool
    {
        $email = $email !== null ? Str::lower(trim($email)) : null;

        if ($email === null || $email === '') {
            return false;
        }

        $stored = $match->user?->email;

        if ($stored === null || trim($stored) === '') {
            return false;
        }

        return Str::lower(trim($stored)) !== $email;
    }

    /**
     * @return Builder<Guardian>
     */
    private function baseQuery(int $schoolId)
    {
        return Guardian::withoutGlobalScopes()
            ->whereNull('guardians.deleted_at')
            ->where('guardians.school_id', $schoolId);
    }
}
