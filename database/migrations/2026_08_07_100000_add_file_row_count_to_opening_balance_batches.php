<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ingest-completeness control the batch was missing.
 *
 * `row_count` counts rows STAGED. Until now nothing counted what the file CONTAINED, so a batch
 * could be internally consistent — totals summing exactly to its own rows — and silently short of
 * the extract it claims to represent. The only skip that exists today is the in-file duplicate
 * (`ImportOpeningBalances::validateInto()`), which drops a row before it is staged; any future skip
 * would be invisible the same way.
 *
 * `file_row_count` is data lines CARRYING CONTENT. It is incremented once per record as the first
 * statement of the validation loop, before every `continue` there, and is never conditioned on a
 * row being valid, resolvable or parseable. `file_row_count != row_count` raises a batch-level
 * finding naming the difference and its reason breakdown — including an `unattributed` bucket, so a
 * future skip that forgets to register a reason shows up as an unexplained gap rather than as
 * nothing at all.
 *
 * NOT physical lines, and the difference is deliberate. `ImportOpeningBalances::readCsv()` drops a
 * WHOLLY BLANK line before the records exist, because a blank line carries no claim and raising
 * `ingest_incomplete` over a trailing newline would be a false positive on every real Brookstone
 * extract — a control people learn to ignore is worse than no control. That is the one drop in the
 * run that `file_row_count` structurally cannot see, so it is not left on trust: the reader counts
 * blanks, returns the count (the operator report prints it beside the content count, and the two
 * reconcile to the physical file by eye), and asserts
 * `count($records) + $blankLines === $physicalDataLines` before returning. A second drop path added
 * to that loop breaks the assertion and stops the run, which is what keeps this exemption from
 * quietly widening into "and also the rows we did not fancy".
 *
 * NOT NULL DEFAULT 0 rather than nullable: an un-ingested batch has read zero lines, which is a
 * fact, not an absence. That is the opposite of the three Money totals on this table, which ARE
 * nullable because a batch that aborted mid-parse must present no total rather than a total nobody
 * summed. A count and a sum fail differently.
 *
 * NO file-derived Money totals accompany this, deliberately. A file-derived sum would have to
 * invent a zero for every row whose amounts did not parse — the exact coercion §2's "blank ≠ zero"
 * forbids — and the only rows that would make such a total diverge from the staged one already
 * reject the batch. See the amended §5 of docs/handoff/opening-balance-import-spec.md for what the
 * two controls each defend, and for the meaning of the Money totals that commit 4 must pin down.
 *
 * A separate migration because 2026_08_06_100000 is already pushed on this branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_opening_balance_batches', function (Blueprint $table) {
            $table->unsignedInteger('file_row_count')->default(0)->after('row_count');
        });
    }

    public function down(): void
    {
        Schema::table('finance_opening_balance_batches', function (Blueprint $table) {
            $table->dropColumn('file_row_count');
        });
    }
};
