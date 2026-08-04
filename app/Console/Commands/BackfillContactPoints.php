<?php

namespace App\Console\Commands;

use App\Models\ContactPoint;
use App\Models\DataBackfill;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\Enums\ChannelKey;
use App\Support\AddressNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Populate `contact_points` from the columns that hold contact data today.
 *
 * DESIGNED AGAINST THE PARTIAL RUN, not the happy path. A backfill over a populated
 * database gets interrupted, dry-run-then-real, reversed-then-rerun; the clean
 * single pass is the case that does not happen. Provenance, gating and idempotency
 * are not three features here — they are one resumable design:
 *
 *  - CHUNKED, COMMITTING PER BATCH, so an interruption keeps the work already done.
 *  - IDEMPOTENT on (user_id, channel, normalized_address), so a re-run or a resume
 *    adds nothing and collides with nothing.
 *  - THE MARKER FLIPS ONLY AT TRUE END. See DataBackfill: setting it early would
 *    flip the gated predicate while unreached rows still have no contact points.
 *
 * IDENTITY RESOLVES TO `user_id` BEFORE THE WRITE, which is what makes the key
 * collapse rather than duplicate. `guardians.phone`, `guardians.whatsapp_number` and
 * `teachers.phone` each carry a user_id, so all three passes reduce to one owner.
 * Keying on guardian_id — or treating the tables as independent streams — looks
 * identical until a user who is BOTH a guardian and a teacher shares one phone, at
 * which point the re-run manufactures exactly the duplicate the key was meant to
 * prevent.
 *
 * IT WRITES THROUGH THE MODEL, deliberately, rather than bulk-inserting. ContactPoint
 * derives `normalized_address` and `address_hash` on save; a raw insert would be a
 * SECOND writer able to produce a row whose normalized form disagrees with its raw
 * one — invisible in every listing, and never matching a suppression. The cost is a
 * query per row on a one-time operation, which is the right trade.
 *
 * THE SENTINEL IS EXCLUDED AND ITS PHONE REROUTED — and the phone comes from
 * `guardians.phone`, NEVER from the sentinel's localpart. `{phone}@no-email.local`
 * interpolates the phone at CREATION and nothing re-mints it, so the localpart is a
 * frozen snapshot that diverges the moment a guardian updates their number. Parsing
 * it would mint a contact point for a number the guardian has already replaced.
 * Feeding the sentinel through as an email would be worse still: it would mint a
 * real-looking email contact point for every phone-only guardian, making "has no
 * contact point" permanently false for exactly the population that needs it true.
 */
class BackfillContactPoints extends Command
{
    protected $signature = 'contacts:backfill
        {--dry-run : Report what would be created, write nothing and set no marker}
        {--chunk=500 : Rows per batch}';

    protected $description = 'Populate contact_points from user/guardian/teacher contact columns';

    /** @var array<string, int> */
    private array $stats = [
        'created' => 0,
        'existing' => 0,
        'skipped_unnormalizable' => 0,
        'skipped_synthetic_email' => 0,
        'people_with_no_contact_point' => 0,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        if (! $dryRun) {
            DataBackfill::query()->updateOrCreate(
                ['key' => DataBackfill::CONTACT_POINTS],
                // started_at only. completed_at stays null until the very end —
                // an interrupted run must read as NOT done.
                ['started_at' => now(), 'completed_at' => null],
            );
        }

        $this->backfillGuardians($chunk, $dryRun);
        $this->backfillTeachers($chunk, $dryRun);

        if ($dryRun) {
            $this->report($dryRun);

            // THE DRY-RUN IS THE CENSUS. `people_with_no_contact_point` is the count
            // a SQL `TRIM(phone) <> ''` cannot produce, because it counts strings
            // while this counts NORMALIZABLE, REACHABLE addresses. The gap between
            // the two is the placeholder data in the column — `n/a`, `-`, names, and
            // the toll-free numbers people type when a field is required.
            return self::SUCCESS;
        }

        // ⚠️ AFTER EVERYTHING, AND ONLY HERE.
        DataBackfill::query()->where('key', DataBackfill::CONTACT_POINTS)->update([
            'completed_at' => now(),
            'stats' => $this->stats,
            'updated_at' => now(),
        ]);

        $this->report($dryRun);

        return self::SUCCESS;
    }

    private function backfillGuardians(int $chunk, bool $dryRun): void
    {
        $this->eachChunk(
            Guardian::query()->withoutGlobalScopes()->whereNotNull('user_id')->with('user'),
            $chunk,
            function (Guardian $guardian) use ($dryRun): void {
                $user = $guardian->user;

                if ($user === null) {
                    return;
                }

                $before = $this->stats['created'] + $this->stats['existing'];

                $this->storeEmailFromColumn($user, $dryRun);

                // PHONE — from the COLUMN. Both channels, because SMS and WhatsApp
                // are different transports to the same number and are suppressed
                // independently; collapsing them would let one suppression silence
                // both.
                $this->store($user, ChannelKey::SMS, (string) $guardian->phone, 'backfill:guardians.phone', $dryRun);
                $this->store($user, ChannelKey::WHATSAPP, (string) $guardian->whatsapp_number, 'backfill:guardians.whatsapp_number', $dryRun);

                if ($before === $this->stats['created'] + $this->stats['existing']) {
                    // No usable address of any kind. Counted rather than passed over:
                    // this is the population with no contact path at all, which the
                    // school wants to know about independently of this migration.
                    $this->stats['people_with_no_contact_point']++;
                }
            },
        );
    }

    private function backfillTeachers(int $chunk, bool $dryRun): void
    {
        $this->eachChunk(
            Teacher::query()->withoutGlobalScopes()->whereNotNull('user_id')->with('user'),
            $chunk,
            function (Teacher $teacher) use ($dryRun): void {
                $user = $teacher->user;

                if ($user === null) {
                    return;
                }

                $this->storeEmailFromColumn($user, $dryRun);

                $this->store($user, ChannelKey::SMS, (string) $teacher->phone, 'backfill:teachers.phone', $dryRun);
            },
        );
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  callable(mixed): void  $handler
     */
    private function eachChunk(Builder $query, int $chunk, callable $handler): void
    {
        // chunkById, not chunk: the offset form re-reads a shifting window when the
        // loop writes, and this loop writes.
        $query->chunkById($chunk, function ($rows) use ($handler): void {
            foreach ($rows as $row) {
                $handler($row);
            }
        });
    }

    /**
     * The email pass — reading the COLUMN, never `hasDeliverableEmail()`.
     *
     * ⚠️ THE PREDICATE'S MEANING IS ABOUT TO INVERT. Today it answers "does
     * `users.email` hold a real address"; after the cutover it answers "does this
     * person have an email CONTACT POINT". A backfill that asks it is circular the
     * moment the flip lands: re-run against flipped code and every guardian without a
     * contact point is judged to have no email, so the run that was supposed to
     * create them mints zero — and it fails silently, because "no email to migrate"
     * and "no email points created" are the same observation.
     *
     * A backfill must read the source it is migrating FROM. That is the column, plus
     * the sentinel exclusion stated here rather than borrowed. The duplication with
     * the predicate is deliberate and temporary: the predicate is on its way to
     * meaning something else, and coupling to it would tie this command to a
     * definition that no longer describes its input.
     */
    private function storeEmailFromColumn(User $user, bool $dryRun): void
    {
        // ⚠️ TRIMMED FIRST, AND THE TRIM IS LOAD-BEARING. AddressNormalizer::email()
        // trims before it validates; this check did not. The two therefore disagreed
        // on a PADDED sentinel: `"…@no-email.local "` failed str_ends_with, escaped
        // the exclusion, and was then trimmed back into a valid address by the
        // normalizer — minting an email contact point for exactly the phone-only
        // guardian the exclusion exists to protect. Post-cutover that flips
        // hasDeliverableEmail() true for them, and bulk mail plus the reset broker
        // aim at an address that can never receive.
        //
        // Same trim-mismatch class as the phone column: one view trims, the other
        // does not, and the disagreement is invisible until a row lands in the gap.
        $raw = trim((string) $user->email);

        if ($raw === '') {
            return;
        }

        // The sentinel is STRUCTURALLY VALID, so the normalizer accepts it — the
        // exclusion has to be explicit or every phone-only guardian gains a
        // real-looking email contact point.
        //
        // THE SHARED PREDICATE, not a second inlined str_ends_with. This command and
        // User::hasDeliverableEmail() previously carried one each, and adding a trim
        // to only this one left them disagreeing about a padded sentinel.
        if (User::isSyntheticEmail($raw)) {
            $this->stats['skipped_synthetic_email']++;

            return;
        }

        $this->store($user, ChannelKey::EMAIL, $raw, 'backfill:users.email', $dryRun);
    }

    private function store(User $user, ChannelKey $channel, string $rawAddress, string $source, bool $dryRun): void
    {
        $normalized = match ($channel) {
            ChannelKey::EMAIL => AddressNormalizer::email($rawAddress),
            default => AddressNormalizer::phone($rawAddress),
        };

        if ($normalized === null) {
            if (trim($rawAddress) !== '') {
                // `n/a`, `-`, a name, a toll-free placeholder. Counted, because the
                // difference between this and a SQL string count IS the census.
                $this->stats['skipped_unnormalizable']++;
            }

            return;
        }

        $exists = ContactPoint::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel->value)
            ->where('normalized_address', $normalized)
            ->exists();

        if ($exists) {
            // The re-run and resume path, and the dual-identity collapse: a user who
            // is both a guardian and a teacher on one phone reaches here on the
            // second pass and adds nothing.
            $this->stats['existing']++;

            return;
        }

        $this->stats['created']++;

        if ($dryRun) {
            return;
        }

        ContactPoint::query()->create([
            'user_id' => $user->id,
            'channel' => $channel->value,
            'address' => $rawAddress,
            'source' => $source,
        ]);
    }

    private function report(bool $dryRun): void
    {
        $this->table(
            ['metric', 'count'],
            collect($this->stats)->map(fn ($v, $k) => [$k, $v])->values()->all(),
        );

        if ($dryRun) {
            $this->info('DRY RUN — nothing written, no marker set.');
            $this->line('`skipped_unnormalizable` is the placeholder data a SQL string count reports as a phone.');
        }
    }
}
