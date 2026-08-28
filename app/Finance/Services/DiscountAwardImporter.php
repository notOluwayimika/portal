<?php

namespace App\Finance\Services;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveDiscountPolicyChange;
use App\Finance\Actions\AwardStudentDiscount;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\DiscountAwardImportOutcome;
use App\Finance\Enums\DiscountBase;
use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\StudentDiscountAward;
use App\Models\Import;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * THE BSS SCHOLARSHIP LIST, CARRIED IN. Brookstone's accounts team holds it outside the system — a
 * spreadsheet pairing each student with the percentage they were awarded — and this turns one such
 * file into rows of `finance_student_discount_awards`.
 *
 * ── IT RESOLVES POLICIES; IT NEVER CREATES ONE. FAIL CLOSED ──────────────────────────────────────
 *
 * A row names a PAIR — a percentage, and what that percentage comes off — and this looks for an
 * ACTIVE policy in the acting School matching it. If none matches, the row is REJECTED with a reason
 * naming the pair. It does not create the missing policy, and no future edit may make it.
 *
 * The catalog has exactly ONE sanctioned writer, {@see ApproveDiscountPolicyChange}, so "the ED
 * approved these terms" is a fact about every row rather than a convention. Brookstone's eight BSS
 * policies — four percentages across the two bases — are authored through that governance chain
 * BEFORE this import runs. Eight rows is exactly the size at which a seeder looks like the obvious
 * tool, which is why this paragraph exists.
 *
 * THE ARCH ARM THAT GUARDS THAT INVARIANT CANNOT SEE A RAW WRITE. It is a text grep over `app_path()`
 * for one Eloquent call on the model, so it is blind to `database/`, to a query-builder insert on the
 * table, and to `insert()`, `firstOrCreate()` and `updateOrCreate()` — see
 * docs/handoff/tickets/the-catalog-single-writer-arch-arm-cannot-see-a-raw-insert.md. Until that
 * ticket closes, the constraint on this file is carried by the reader, not by a gate.
 *
 * THE NEEDLE IS DESCRIBED AND NOT QUOTED, DELIBERATELY. Spelling it out here put this file in the
 * arm's own writer list — a docblock about the grep tripping the grep — and the fix was NOT to add
 * this filename to the expectation, which would have blinded the guard for the sake of a comment.
 *
 * ── AMBIGUITY IS A REFUSAL, NOT A CHOICE ─────────────────────────────────────────────────────────
 *
 * `finance_discount_policies` carries a state-scoped unique on NAME, not on (percent, base), so two
 * ACTIVE policies can legitimately sit on the same pair — one requiring per-application approval and
 * one not, say. This refuses such a row rather than taking the first: picking silently would price a
 * named child off an arbitrary row, and which row it picked would be invisible afterwards.
 *
 * ── THE JOIN KEY IS EXACT, AND THAT IS THE WHOLE OF ITS SAFETY ───────────────────────────────────
 *
 * `admission_number` is unique per SCHOOL — `students_school_id_admission_number_unique`
 * (declared in database/migrations/2026_04_26_121302_create_students_table.php) — never globally.
 * So the key
 * only identifies a student once a School is fixed, and the roster comes from
 * {@see BillableEnrollmentProvider::admissionNumberIndex()}, which is scoped to the ACTIVE School.
 * A number belonging to another school's student is therefore not "the wrong student", it is NO
 * student, and the row is rejected.
 *
 * THE MATCH IS TRIM-THEN-EXACT. The uploaded cell is trimmed, because leading and trailing spaces
 * are what spreadsheets do and no operator can see them; it is then compared byte-for-byte against
 * the stored value. Nothing is case-folded and nothing is fuzzy-matched: the port's own docblock
 * says why `matchingStudentIds()` (a `LIKE %term%`) must never be used as a join — it resolves "A1"
 * onto "A100" — and a fuzzy key on a file that decides what families pay is the worst failure
 * available here. A student stored with a stray space is a MISS, reported as one, which is a better
 * answer than a plausible wrong one.
 *
 * ── THE REPORT IS FOR THE PERSON WHO FILLED IN THE SHEET ─────────────────────────────────────────
 *
 * Every row is echoed back BY WHAT THEY TYPED — their line number and their three cells, verbatim,
 * whitespace included — never by anything read back out of the database. A refusal that says
 * "student #4821 holds no scholarship" is true and useless; the ids the domain raises are rewritten
 * into the admission number the uploader wrote (see {@see self::speakTo()}), which is a substitution
 * on a token this file controls rather than a second copy of the Action's rules.
 *
 * AND NO SQL EVER REACHES IT. A `QueryException`'s message interpolates its BINDINGS into the SQL
 * (`Illuminate\Database\QueryException::formatMessage`), so passing one through to a downloadable
 * report publishes the row's data as raw SQL — which is exactly what the guardian import does today
 * (docs/handoff/tickets/guardian-import-result-export-leaks-sql-with-bindings.md). It is caught
 * FIRST, before the generic arm, logged in full and reported as a fixed sentence.
 */
final class DiscountAwardImporter
{
    /**
     * THE FILE FORMAT. Three columns, and the map is the single definition: it drives the template
     * the platform issues AND the reader below, so the sheet a bursar downloads cannot drift from
     * the sheet the importer accepts. Same constant-driven shape, for the same reason, as
     * {@see OpeningBalanceFileValidator::COLUMNS}.
     *
     * `notes` AND `format` ARE OPERATOR-FACING TEXT, not notes to a developer. They are what the
     * accounts team reads on the template, and a rule that lives only in a spec is a rule the person
     * filling in the file never sees.
     *
     * ALL THREE ARE REQUIRED, AND THE THIRD ONE ESPECIALLY. See self::APPLIES_TO.
     *
     * @var array<string, array{required: bool, format: string, example: string, notes: string}>
     */
    public const COLUMNS = [
        'admission_number' => [
            'required' => true,
            'format' => 'text, exactly as it appears in the portal',
            'example' => 'STU2025001',
            // The Excel warning belongs on the column, because this note is what the person typing
            // reads. Same defect, same wording, same reason as the opening-balance template: we
            // cannot detect it afterwards, so the only possible defence is the person typing.
            'notes' => 'REQUIRED. The student, by their admission number. They must already exist in this school — nobody is created by this import, and a number belonging to another school matches nobody. FORMAT THIS COLUMN AS TEXT IN EXCEL BEFORE YOU TYPE OR PASTE INTO IT: treated as a number, Excel silently deletes leading zeros, so 00123 is saved as 123 and we cannot tell afterwards that it happened.',
        ],
        'discount_percentage' => [
            'required' => true,
            // 1..100 is not a number chosen here. `finance_discount_policies.percent` is
            // `unsignedTinyInteger` under a CHECK reading `percent BETWEEN 1 AND 100`
            // (declared in database/migrations/2026_07_26_140000_create_finance_discount_policies.php
            // as `finance_discount_policies_basis_exclusive`), so a policy outside that range cannot exist
            // and a row asking for one could never match anything.
            'format' => 'a whole number from 1 to 100',
            'example' => '50',
            'notes' => 'REQUIRED. The percentage this student was awarded, as a whole number: write 50, not 0.5 and not 50.5. A trailing % sign is fine. There must already be an APPROVED discount policy at this percentage and this "applies to" — the import never creates one, and a pair with no policy is refused with that pair named.',
        ],
        'discount_applies_to' => [
            'required' => true,
            'format' => 'TUITION ONLY  or  THE WHOLE BILL',
            'example' => 'TUITION ONLY',
            // THE COLUMN THAT CANNOT BE DEFAULTED — the argument is in self::APPLIES_TO.
            'notes' => 'REQUIRED, on every row, and there is no default. Write TUITION ONLY or THE WHOLE BILL. It is per student and it changes the money: 100% of TUITION ONLY still leaves the child paying for transport and anything else the school does not treat as discountable, while 100% of THE WHOLE BILL leaves them paying nothing at all. Those are different amounts, so we will not guess which one you meant.',
        ],
    ];

    /**
     * WHAT THE THIRD COLUMN MAY SAY, and what each phrase does.
     *
     * THE WORDS ARE THE BURSAR'S, NOT OURS. `discountable` and `total` are the enum's values
     * ({@see DiscountBase}) and they mean nothing to the person filling in the sheet: "discountable"
     * is circular and "total" reads like a sum. The phrases below say what HAPPENS to the bill,
     * which is the same standard the scholarship-kind control was held to.
     *
     * THE LABEL IS BROOKSTONE'S OWN AND THE NOTE IS THE MECHANISM — read both. Brookstone describe
     * the two offers as "50% off tuition only" and "50% off the whole bill", so TUITION ONLY is
     * their vocabulary. What it resolves to is `DiscountBase::Discountable`, which is every charge
     * line whose fee item is marked `is_discountable` — tuition in Brookstone's schedule, and
     * whatever else a school marks. The COLUMNS note above states that explicitly rather than
     * letting the short label carry a claim it cannot support on a schedule nobody has read.
     *
     * THE ENUM VALUES ARE ACCEPTED TOO, and cost one array entry. A file exported by somebody
     * reading the API, or a row copied out of the catalog screen, arrives with `discountable` or
     * `total` in it; refusing that would be pedantry, and accepting it cannot be misread as anything
     * else. Everything not in this map is refused — no default, no nearest match, no empty-means-
     * tuition. The third column is the base axis and it is per student; a default would silently
     * price a child on the cheaper reading of a sheet nobody re-checks.
     *
     * MATCHING IS CASE-INSENSITIVE AND WHITESPACE-COLLAPSING, and nothing else. "the whole bill",
     * "THE  WHOLE  BILL" and " The Whole Bill " are one answer. "Whole bill", "everything" and
     * "all fees" are not answers and are refused by name.
     *
     * @var array<string, DiscountBase>
     */
    public const APPLIES_TO = [
        'TUITION ONLY' => DiscountBase::Discountable,
        'THE WHOLE BILL' => DiscountBase::Total,
        'DISCOUNTABLE' => DiscountBase::Discountable,
        'TOTAL' => DiscountBase::Total,
    ];

    /**
     * The two phrases a template offers and a report speaks, in the order they are offered. Derived
     * from {@see self::APPLIES_TO} nowhere: these are the CANONICAL forms, and the map above also
     * carries the two aliases, so a derivation would have to encode which entries are canonical.
     *
     * @var array<string, DiscountBase>
     */
    public const APPLIES_TO_CANONICAL = [
        'TUITION ONLY' => DiscountBase::Discountable,
        'THE WHOLE BILL' => DiscountBase::Total,
    ];

    /**
     * The rules that belong to no single column, so the Columns list has nowhere to put them. Each
     * one is a rule whose failure is expensive and which the import cannot correct on the operator's
     * behalf.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const NOTES = [
        [
            'Delete the two example rows before you upload',
            'The template ships with two filled-in rows so you can see the shape — one on each of the '
                .'two "applies to" answers, because that column is the one that changes the money. They '
                .'name students who do not exist, so leaving them in costs you two REFUSED rows on the '
                .'report and nothing else; deleting them keeps the report about your own list.',
        ],
        [
            'The policies must exist BEFORE you upload',
            'This import never creates a discount policy. It looks for one that is already APPROVED and '
                .'ACTIVE at the percentage and the "applies to" your row names, and refuses the row if there '
                .'is none — naming the pair it could not find. Every percentage you intend to use, on each '
                .'of TUITION ONLY and THE WHOLE BILL, must have been submitted and approved through the '
                .'discount-policy approval flow first. That approval is what makes the figure legitimate; '
                .'this sheet only says which student is on which already-approved figure.',
        ],
        [
            'THE THIRD COLUMN IS MONEY — it has no default',
            'TUITION ONLY discounts only the fees the school treats as discountable. THE WHOLE BILL '
                .'discounts everything on the invoice. At 100% those are the difference between a child who '
                .'still pays for the bus and a child who pays nothing. Every row must say which, and a blank '
                .'REJECTS the row rather than being read as either one.',
        ],
        [
            'Re-uploading the same sheet is safe',
            'A student who is already on exactly the policy their row names is reported as ALREADY AWARDED '
                .'and nothing changes — it is not a failure and it does not need fixing. But a row asking '
                .'for a DIFFERENT percentage or a different "applies to" than the student already holds is '
                .'REFUSED: this import does not change an existing award, because changing what a family '
                .'pays is not something a re-upload should do quietly.',
        ],
        [
            'The student must already be on a discount scholarship',
            'A discount is a scholarship scheme, so the child has to be recorded as being on one before '
                .'they can be priced. A student on no scholarship, on a scholarship nobody has yet said is a '
                .'discount scheme, or on a SPONSORED scholarship, is refused and the reason says which. A '
                .'sponsored student is billed by hand and is left out of the bulk run entirely, so a '
                .'discount on one could never be applied to anything.',
        ],
        [
            'Extra columns are ignored',
            'A column this format does not name is read past without comment — the student\'s name is the '
                .'one you will want to keep in the sheet, and you may. Nothing reads it, and nothing is '
                .'matched on it: the admission number is the only join key.',
        ],
    ];

    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
        private readonly AwardStudentDiscount $award,
    ) {}

    /**
     * Read the CSV into line-numbered associative records, with the required-column check taken from
     * {@see self::COLUMNS} so the header rule cannot fall behind the format.
     *
     * IT IS {@see OpeningBalanceFileValidator::read()}'S SHAPE, INCLUDING THE ACCOUNTING THROW, and
     * the accounting is the part worth having. Every physical data line must become either a record
     * or a counted blank; the day someone adds a third `continue` here — `if
     * ($values['admission_number'] === '') continue;` is the plausible one — a row disappears
     * upstream of the report, and `total_rows` stops being able to prove that every line of the
     * bursar's sheet was answered. This throw turns that silent narrowing into a stopped run.
     *
     * @return array{records: list<array{line: int, values: array<string, string>}>, blankLines: int}
     *
     * @throws InvalidArgumentException
     */
    public function read(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('The file could not be opened.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false || $header === [null]) {
                throw new InvalidArgumentException('The file is empty; a header row naming the columns is required.');
            }

            // A UTF-8 BOM on the first cell would make the first header name unmatchable — which
            // presents as "missing required column: admission_number" over a file that visibly has
            // one, and Excel writes a BOM.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

            $required = array_keys(array_filter(self::COLUMNS, fn (array $spec) => $spec['required']));
            $missing = array_diff($required, $header);
            if ($missing !== []) {
                throw new InvalidArgumentException(
                    'Missing required column(s): '.implode(', ', $missing)
                    .'. Download the template from the button above and fill that.'
                );
            }

            $records = [];
            $blankLines = 0;
            $line = 1; // the header is line 1; data starts at 2, which is the number an operator sees

            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($row === [null]) {
                    $blankLines++; // a wholly blank line carries no claim — counted, not swallowed

                    continue;
                }

                $values = [];
                foreach ($header as $index => $name) {
                    $values[$name] = (string) ($row[$index] ?? '');
                }
                $records[] = ['line' => $line, 'values' => $values];
            }

            $physical = $line - 1; // $line counts the header
            if (count($records) + $blankLines !== $physical) {
                throw new InvalidArgumentException(sprintf(
                    'Reader accounting failed: %d record(s) + %d blank line(s) != %d physical data line(s). '
                    .'A drop path was added to read() that neither reports a row nor counts a blank, so the '
                    .'report can no longer account for every line of the file. Register it, or do not drop it.',
                    count($records), $blankLines, $physical,
                ));
            }

            return ['records' => $records, 'blankLines' => $blankLines];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Turn the records into awards, one row at a time, and answer for every one of them.
     *
     * NOTHING IS BATCHED AND NOTHING IS WRAPPED IN ONE TRANSACTION, deliberately. Each row is an
     * independent decision about one child: a sheet of ninety-one rows with three bad ones should
     * award eighty-eight, not none. {@see AwardStudentDiscount} owns the transaction around the
     * single award plus its audit entry, which is the unit that must be atomic.
     *
     * THE ROSTER IS READ ONCE, before the loop. A per-row lookup would be ninety-one queries and
     * would also let the answer drift mid-run.
     *
     * ORDER OF REFUSALS IS DELIBERATE: shape of the row, then the student, then the policy, then
     * what the student already holds, then the domain. Each step's reason is only meaningful once
     * the ones before it have passed — "no active policy at 50% of the whole bill" is a confusing
     * thing to say about a row whose admission number matches nobody.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $records
     * @return list<array{line_number: int, admission_number: string, discount_percentage: string, discount_applies_to: string, outcome: DiscountAwardImportOutcome, reason: string}>
     */
    public function import(Import $import, array $records): array
    {
        $schoolId = (int) $import->school_id;
        $actorId = (int) $import->user_id;

        // student_id keyed by the admission number EXACTLY as stored. See the class docblock for why
        // the match is trim-then-exact and not case-folded.
        $roster = [];
        foreach ($this->enrollments->admissionNumberIndex() as $entry) {
            if ($entry['admission_number'] !== null) {
                $roster[$entry['admission_number']] = $entry['student_id'];
            }
        }

        $results = [];

        foreach ($records as $record) {
            $results[] = $this->importRow($record, $roster, $schoolId, $actorId);
        }

        return $results;
    }

    /**
     * The canonical phrase for a base — what a report says back to a bursar who wrote an alias.
     */
    public static function appliesToLabel(DiscountBase $base): string
    {
        foreach (self::APPLIES_TO_CANONICAL as $phrase => $case) {
            if ($case === $base) {
                return $phrase;
            }
        }

        // Unreachable while DiscountBase has two cases, both mapped above. Stated rather than
        // silently returning the enum value, because a third case added to DiscountBase without a
        // phrase here is exactly the drift that would put `discountable` in front of a bursar.
        return $base->value;
    }

    /**
     * @param  array<string, int>  $roster
     * @return array{line_number: int, admission_number: string, discount_percentage: string, discount_applies_to: string, outcome: DiscountAwardImportOutcome, reason: string}
     */
    private function importRow(array $record, array $roster, int $schoolId, int $actorId): array
    {
        $values = $record['values'];

        // VERBATIM, whitespace and all. These three are echoed into the report unchanged, because
        // the report's job is to point at a line of the operator's own file — and a trailing space
        // they cannot see on screen is precisely the thing they need shown back to them.
        $typedAdmission = $values['admission_number'] ?? '';
        $typedPercent = $values['discount_percentage'] ?? '';
        $typedAppliesTo = $values['discount_applies_to'] ?? '';

        $row = fn (DiscountAwardImportOutcome $outcome, string $reason = ''): array => [
            'line_number' => $record['line'],
            'admission_number' => $typedAdmission,
            'discount_percentage' => $typedPercent,
            'discount_applies_to' => $typedAppliesTo,
            'outcome' => $outcome,
            'reason' => $reason,
        ];

        $reject = fn (string $reason): array => $row(DiscountAwardImportOutcome::Rejected, $reason);

        // ── 1 · the shape of the row ────────────────────────────────────────────────────────────
        $admission = trim($typedAdmission);
        if ($admission === '') {
            return $reject('The admission number is blank. Every row must name the student it is awarding.');
        }

        $percent = self::parsePercentage($typedPercent);
        if ($percent === null) {
            return $reject(sprintf(
                'The percentage [%s] is not a whole number from 1 to 100. Write 50, not 0.5 and not 50.5; '
                .'a trailing %% sign is fine.',
                $typedPercent,
            ));
        }

        $base = self::parseAppliesTo($typedAppliesTo);
        if ($base === null) {
            return $reject(sprintf(
                'The "applies to" column says [%s], which is not one of the two answers. Write %s. '
                .'It has no default: at 100%% the two are the difference between a child who still pays '
                .'for transport and a child who pays nothing.',
                $typedAppliesTo,
                implode(' or ', array_keys(self::APPLIES_TO_CANONICAL)),
            ));
        }

        // ── 2 · the student ─────────────────────────────────────────────────────────────────────
        $studentId = $roster[$admission] ?? null;
        if ($studentId === null) {
            return $reject(sprintf(
                'No student in this school has the admission number [%s]. Admission numbers are unique '
                .'within a school, so a number from another school matches nobody here. Check it against '
                .'the portal — and check that Excel has not dropped a leading zero.',
                $admission,
            ));
        }

        // ── 3 · the policy for the pair ─────────────────────────────────────────────────────────
        $pair = sprintf('%d%% of %s', $percent, self::appliesToLabel($base));

        $policies = DiscountPolicy::query()
            ->where('school_id', $schoolId)
            ->where('status', DiscountPolicyStatus::Active->value)
            ->where('basis', DiscountBasis::Percent->value)
            ->where('percent', $percent)
            ->where('base', $base->value)
            ->orderBy('id')
            ->get();

        if ($policies->isEmpty()) {
            return $reject(sprintf(
                'This school has no active discount policy for %s. The policy has to be approved before '
                .'the award can be made — this import never creates one. Submit it through the '
                .'discount-policy approval flow, wait for approval, then upload this sheet again.',
                $pair,
            ));
        }

        if ($policies->count() > 1) {
            return $reject(sprintf(
                'This school has %d active discount policies for %s (%s), so this row does not say which '
                .'one the student is on. Retire the ones that are no longer in use, then upload again — '
                .'we will not choose on your behalf.',
                $policies->count(),
                $pair,
                $policies->pluck('name')->implode(', '),
            ));
        }

        $policy = $policies->first();

        // ── 4 · what the student already holds ──────────────────────────────────────────────────
        // Read BEFORE the Action, so the two cases it cannot tell apart are told apart here. The
        // Action refuses any second award with one message ("remove the existing one first"), which
        // is right for it — replacing a child's pricing is deliberately not built — but a re-upload
        // of an UNCHANGED sheet is the normal case and must not read as ninety-one failures.
        $existing = StudentDiscountAward::query()->where('student_id', $studentId)->first();

        if ($existing instanceof StudentDiscountAward) {
            if ((int) $existing->discount_policy_id === (int) $policy->id) {
                return $row(
                    DiscountAwardImportOutcome::AlreadyAwarded,
                    sprintf('Already on %s. Nothing changed — this row needs no action.', $pair),
                );
            }

            // A DIFFERENT policy is a request to CHANGE what this family pays, and it is refused
            // rather than folded into "already awarded" — a bursar reads the word "already" as "no
            // action needed", and this row asked for something that did not happen.
            $held = $existing->policy;
            $heldPair = $held instanceof DiscountPolicy && $held->percent !== null
                ? sprintf('%d%% of %s', $held->percent, self::appliesToLabel($held->base))
                : 'a different discount policy';

            return $reject(sprintf(
                'This student is already on %s, and this row asks for %s. This import does not change an '
                .'award that already exists. Remove the existing award first if the change is intended.',
                $heldPair,
                $pair,
            ));
        }

        // ── 5 · the domain ──────────────────────────────────────────────────────────────────────
        try {
            $this->award->handle($studentId, (int) $policy->id, $actorId);
        } catch (AuthorizationException) {
            // The uploader's own ability, re-checked at the Action off-request. It is the same
            // refusal the route gave at upload time, so reaching it here means the grant was revoked
            // between the upload and the run — worth saying plainly rather than as a row defect.
            return $reject(
                'You no longer hold the permission to award a student discount, so this row was not '
                .'applied. Nothing in the file is wrong.'
            );
        } catch (QueryException $e) {
            // FIRST, before the generic arm. A QueryException's message interpolates its bindings
            // into the SQL, and this message lands in a spreadsheet somebody downloads.
            Log::error('Discount award import: the database rejected a row', [
                'school_id' => $schoolId,
                'line' => $record['line'],
                'error' => $e->getMessage(),
            ]);

            return $reject(
                'The database rejected this row. Nothing was awarded for this student. This is a fault in '
                .'the portal, not in your file — report it with this line number.'
            );
        } catch (BusinessRuleException $e) {
            return $reject($this->speakTo($e->getMessage(), $studentId, $admission));
        }

        return $row(DiscountAwardImportOutcome::Awarded, sprintf('Awarded %s.', $pair));
    }

    /**
     * Rewrite the domain's `[#<student id>]` token into the admission number the uploader typed.
     *
     * WHY A SUBSTITUTION AND NOT A RE-PHRASING. {@see AwardStudentDiscount} raises three refusals a
     * bursar meets — no scholarship, an unconfigured scholarship, a sponsored one — and each carries
     * reasoning worth reading. Re-writing them here would be a second copy of rules that can drift
     * from the ones actually enforced, which is the defect the single COLUMNS map exists to avoid one
     * layer up. So the sentence is the Action's; only the identifier is ours, and it is a token this
     * file constructed the argument for.
     *
     * IT IS NOT A REDACTION. An internal student id is not a name and leaks nothing; it is simply
     * useless to the person holding the spreadsheet, who knows the admission number and nothing else.
     */
    private function speakTo(string $message, int $studentId, string $admission): string
    {
        return str_replace("[#{$studentId}]", "[{$admission}]", $message);
    }

    /**
     * A whole number from 1 to 100, or null. A trailing `%` is accepted because a bursar typing a
     * percentage writes one; nothing else is coerced.
     *
     * `0` IS REFUSED AND SO IS `101`, and neither bound is chosen here: `finance_discount_policies`
     * declares `percent` as `unsignedTinyInteger` under a CHECK reading `percent BETWEEN 1 AND 100`
     * (database/migrations/2026_07_26_140000_create_finance_discount_policies.php). A row outside that range could
     * not match a policy however this parsed it — refusing here just names the reason.
     *
     * `ctype_digit` AND NOT `is_numeric` / `(int)`. `(int) '50.5'` is 50 and `(int) '5e1'` is 50, so
     * a cast would silently round a sheet that says something else and award a percentage nobody
     * wrote. `'050'` is accepted and is 50 — a leading zero on a percentage is a spreadsheet
     * artefact, not a different number.
     */
    public static function parsePercentage(string $raw): ?int
    {
        $value = trim($raw);

        if (str_ends_with($value, '%')) {
            $value = trim(substr($value, 0, -1));
        }

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        $percent = (int) $value;

        return ($percent >= 1 && $percent <= 100) ? $percent : null;
    }

    /**
     * One of the two phrases (or one of the two enum-value aliases), case-insensitive with internal
     * whitespace collapsed. Anything else — including blank — is null, and null is a refusal.
     */
    public static function parseAppliesTo(string $raw): ?DiscountBase
    {
        $normalised = strtoupper((string) preg_replace('/\s+/u', ' ', trim($raw)));

        return self::APPLIES_TO[$normalised] ?? null;
    }
}
