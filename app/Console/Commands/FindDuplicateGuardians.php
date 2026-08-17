<?php

namespace App\Console\Commands;

use App\Models\Guardian;
use App\Support\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Find the duplicate `guardians` rows that `guardians:merge` exists to collapse.
 *
 * TWO GROUPINGS, AND THEY ARE NOT THE SAME CLAIM.
 *
 * (1) Same (user_id, school_id) — CERTAIN. A Guardian is a per-school record for
 *     one User (§6.2); two live rows for the same pair is the defect itself, and
 *     it is the exact set that has to be empty before a uniqueness constraint can
 *     be added. This command exits non-zero while any exist, so it doubles as
 *     that migration's pre-flight.
 *
 * (2) Same phone within a school — LIKELY. This is the email-less case: with no
 *     address, `User::where('email', null)->first()` never matches under MySQL,
 *     so each submission mints a fresh user AND a fresh guardian and grouping (1)
 *     cannot see them. Only the phone ties them together, and a shared phone is
 *     evidence rather than proof — two siblings' parents can share a household
 *     line. Nothing here is auto-merged; the operator reads the group and decides.
 *
 * Matching semantics are GuardianImportService::lookupExistingInDb's, not a third
 * rule: within a school, a number matches against either `phone` or
 * `whatsapp_number`. Normalisation is App\Support\PhoneNormalizer — the same
 * function the write path canonicalises with — because rows created before that
 * boundary existed still hold raw formatting.
 *
 * Ids and counts only. Run by engineers against a copy of production.
 */
class FindDuplicateGuardians extends Command
{
    protected $signature = 'guardians:find-duplicates {--school= : restrict to one school id}';

    protected $description = 'Report duplicate guardian records: certain (same user+school) and likely (shared phone)';

    public function handle(): int
    {
        $schoolId = $this->option('school') !== null ? (int) $this->option('school') : null;

        // Global scopes dropped: Guardian::applySchoolScope matches
        // `school_id = active OR user_id has access to active`, which would let
        // another school's rows into a per-school grouping.
        $guardians = Guardian::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->when($schoolId !== null, fn ($q) => $q->where('school_id', $schoolId))
            ->orderBy('id')
            ->get(['id', 'user_id', 'school_id', 'phone', 'whatsapp_number']);

        $this->line('Live guardian records examined: '.$guardians->count()
            .($schoolId !== null ? " (school#{$schoolId})" : ' (all schools)'));
        $this->newLine();

        $certain = $this->certainGroups($guardians);
        $likely = $this->likelyGroups($guardians);

        $this->line('(1) CERTAIN — more than one live guardian row for the same user in the same school: '
            .count($certain));

        if ($certain !== []) {
            $this->table(
                ['school', 'user', 'rows', 'guardians'],
                array_map(fn (array $group) => [
                    "school#{$group['school_id']}",
                    "user#{$group['user_id']}",
                    count($group['guardian_ids']),
                    implode(', ', array_map(fn (int $id) => "guardian#{$id}", $group['guardian_ids'])),
                ], $certain),
            );
        }

        $this->newLine();
        $this->line('(2) LIKELY — distinct live guardian rows sharing a phone number within a school: '
            .count($likely));

        if ($likely !== []) {
            $this->table(
                ['school', 'rows', 'guardians'],
                array_map(fn (array $group) => [
                    "school#{$group['school_id']}",
                    count($group['guardian_ids']),
                    implode(', ', array_map(fn (int $id) => "guardian#{$id}", $group['guardian_ids'])),
                ], $likely),
            );
        }

        $this->newLine();

        if ($certain === []) {
            $this->info('No certain duplicates. A unique index on (user_id, school_id) over live rows would hold today.');

            return self::SUCCESS;
        }

        $this->error(count($certain).' certain duplicate group(s) must be merged before a uniqueness constraint can be added.');
        $this->line('Collapse each with: php artisan guardians:merge --keep=<uuid> --absorb=<uuid> [--apply]');

        return self::FAILURE;
    }

    /**
     * @param  Collection<int, Guardian>  $guardians
     * @return list<array{school_id: int, user_id: int, guardian_ids: list<int>}>
     */
    private function certainGroups($guardians): array
    {
        $groups = [];

        foreach ($guardians as $guardian) {
            $key = $guardian->school_id.':'.$guardian->user_id;
            $groups[$key][] = (int) $guardian->id;
        }

        $out = [];

        foreach ($groups as $key => $ids) {
            if (count($ids) < 2) {
                continue;
            }

            [$school, $user] = explode(':', $key);

            $out[] = ['school_id' => (int) $school, 'user_id' => (int) $user, 'guardian_ids' => $ids];
        }

        return $out;
    }

    /**
     * Connected components over "shares a normalised number within a school".
     *
     * A component rather than a pairwise list because the import lookup's rule is
     * transitive in effect: A's phone matching B's whatsapp and B's phone matching
     * C's are two links in one household, and reporting them as two separate pairs
     * would have an operator merge the same group twice.
     *
     * @param  Collection<int, Guardian>  $guardians
     * @return list<array{school_id: int, guardian_ids: list<int>}>
     */
    private function likelyGroups($guardians): array
    {
        /** @var array<int, int> $parent */
        $parent = [];
        /** @var array<string, int> $seen */
        $seen = [];
        /** @var array<int, int> $schoolOf */
        $schoolOf = [];

        foreach ($guardians as $guardian) {
            $id = (int) $guardian->id;
            $parent[$id] = $id;
            $schoolOf[$id] = (int) $guardian->school_id;
        }

        foreach ($guardians as $guardian) {
            $id = (int) $guardian->id;

            foreach ([$guardian->phone, $guardian->whatsapp_number] as $raw) {
                $number = PhoneNormalizer::normalize($raw);

                if ($number === null) {
                    continue;
                }

                $key = $guardian->school_id.':'.$number;

                if (isset($seen[$key])) {
                    $this->union($parent, $id, $seen[$key]);

                    continue;
                }

                $seen[$key] = $id;
            }
        }

        /** @var array<int, list<int>> $components */
        $components = [];

        foreach (array_keys($parent) as $id) {
            $components[$this->find($parent, $id)][] = $id;
        }

        $out = [];

        foreach ($components as $root => $ids) {
            if (count($ids) < 2) {
                continue;
            }

            sort($ids);

            $out[] = ['school_id' => $schoolOf[$root], 'guardian_ids' => $ids];
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $parent
     */
    private function find(array &$parent, int $id): int
    {
        while ($parent[$id] !== $id) {
            $parent[$id] = $parent[$parent[$id]];
            $id = $parent[$id];
        }

        return $id;
    }

    /**
     * @param  array<int, int>  $parent
     */
    private function union(array &$parent, int $a, int $b): void
    {
        $rootA = $this->find($parent, $a);
        $rootB = $this->find($parent, $b);

        if ($rootA !== $rootB) {
            $parent[max($rootA, $rootB)] = min($rootA, $rootB);
        }
    }
}
