<?php

namespace App\Finance\Enums;

use App\Finance\Services\DiscountAwardImporter;
use App\Models\Import;

/**
 * What became of ONE row of a BSS discount-award sheet. Three outcomes, and the middle one is the
 * reason this is an enum rather than a boolean.
 *
 *   Awarded        — the row resolved to a student, to an ACTIVE policy matching its
 *                    (percentage, applies-to) pair, and the award was written.
 *
 *   AlreadyAwarded — that student is ALREADY on exactly the policy this row asks for. NOT AN ERROR,
 *                    and that is the whole point of separating it: the BSS list is a spreadsheet
 *                    held outside the system and it will be re-uploaded — after a correction, after
 *                    a new student is added to it, after somebody is not sure the first upload
 *                    worked. A bursar who uploads the same sheet twice must not be shown ninety-one
 *                    failures, because a report that cries wolf on the SECOND run is a report nobody
 *                    reads on the third. It maps onto `imports.skipped`, which is the column the
 *                    schema already has for exactly this.
 *
 *   Rejected       — anything else, always with a reason the person who filled in the sheet can act
 *                    on. Maps onto `imports.failed`.
 *
 * A ROW ASKING FOR A DIFFERENT POLICY THAN THE ONE THE STUDENT HOLDS IS `Rejected`, NOT
 * `AlreadyAwarded` — see {@see DiscountAwardImporter} for the argument. Folding the two together
 * would report "already awarded" over a row whose whole purpose was to CHANGE what a family pays,
 * and the bursar would read the word "already" as "no action needed".
 *
 * IT IS NOT A COLUMN. {@see Import} carries counters (`succeeded` / `skipped` / `failed`) and the
 * per-row outcome lives in the downloadable report, so this enum is the vocabulary that keeps the
 * two consistent rather than a fourth thing to migrate.
 */
enum DiscountAwardImportOutcome: string
{
    case Awarded = 'awarded';

    case AlreadyAwarded = 'already_awarded';

    case Rejected = 'rejected';
}
