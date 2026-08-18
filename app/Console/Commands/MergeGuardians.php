<?php

namespace App\Console\Commands;

use App\Models\Guardian;
use App\Services\GuardianService;
use App\Support\ActiveSchool;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Collapse duplicate `guardians` rows into one.
 *
 * WHY A COMMAND AND NOT A SCREEN. The duplicates already in production were made
 * by an operator adding the same person more than once, and the shape of each
 * group — which row keeps which student, which link carries login, which fields
 * are blank on the survivor — is a judgement call that has to be inspected before
 * it is executed. A dry run that prints the whole plan and writes nothing is the
 * inspection step; `--apply` is the same plan, executed. The admin UI is a later
 * change and will call the same service method.
 *
 * DRY RUN IS THE DEFAULT, deliberately. The dangerous direction is an engineer
 * running this against a production copy expecting a report and getting a write.
 *
 * IDS ONLY IN OUTPUT. This is run by engineers against a copy of production;
 * `guardian#<id>` answers every question a name would and does not put a parent's
 * name in a terminal scrollback or a ticket.
 */
class MergeGuardians extends Command
{
    protected $signature = 'guardians:merge
        {--keep= : uuid of the guardian record that survives}
        {--absorb=* : uuid of a guardian record to fold into the keeper (repeatable)}
        {--apply : write the merge; without this the plan is printed and nothing is written}
        {--consolidate-login : end the login on an absorbed account and issue the parent fresh credentials for the keeper}';

    protected $description = 'Merge duplicate guardian records into one, moving student links and soft-deleting the rest';

    public function handle(GuardianService $guardians): int
    {
        $keeperUuid = (string) ($this->option('keep') ?? '');

        /** @var list<string> $absorbUuids */
        $absorbUuids = array_values(array_filter((array) $this->option('absorb')));

        if ($keeperUuid === '' || $absorbUuids === []) {
            $this->error('Both --keep=<uuid> and at least one --absorb=<uuid> are required.');

            return self::FAILURE;
        }

        $keeper = $this->resolve($keeperUuid);

        if (! $keeper) {
            $this->error('No live guardian record found for the --keep uuid.');

            return self::FAILURE;
        }

        $absorbed = new Collection;

        foreach ($absorbUuids as $uuid) {
            $guardian = $this->resolve($uuid);

            if (! $guardian) {
                $this->error('No live guardian record found for one of the --absorb uuids.');

                return self::FAILURE;
            }

            $absorbed->push($guardian);
        }

        $apply = (bool) $this->option('apply');
        $consolidateLogin = (bool) $this->option('consolidate-login');

        try {
            // Off-request, so there is no authenticated user and no session school.
            // Context is taken from the KEEPER'S OWN school_id — never from
            // users.school_id and never from an actor (Constitution 13, ADR 0036/0042).
            $plan = ActiveSchool::runFor(
                (int) $keeper->school_id,
                fn (): array => $guardians->merge($keeper, $absorbed, $apply, $consolidateLogin),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->render($plan);

        return self::SUCCESS;
    }

    /**
     * Global scopes dropped and `deleted_at` pinned: Guardian::applySchoolScope
     * matches `school_id = active OR user_id has access to active`, so under it a
     * multi-school parent's rows in other schools resolve here too.
     */
    private function resolve(string $uuid): ?Guardian
    {
        return Guardian::withoutGlobalScopes()
            ->where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function render(array $plan): void
    {
        $this->newLine();
        $this->line($plan['applied'] ? 'APPLIED.' : 'DRY RUN — nothing was written. Re-run with --apply to execute.');
        $this->line("keeper guardian#{$plan['keeper_id']} on user#{$plan['keeper_user_id']} in school#{$plan['school_id']}");
        $this->line('absorbing '.implode(', ', array_map(fn (int $id) => "guardian#{$id}", $plan['absorbed_ids'])));
        $this->newLine();

        // PRINTED FIRST, BEFORE THE PIVOT TABLES, because it is the only part of
        // this plan that can end a person's access to the portal. Everything else
        // moves rows between two records the same human owns.
        //
        // NOT KEYED ON `can_login`. Authentication reads users.disabled_at and the
        // password hash and never looks at the pivot, so "carries a login" is a
        // question about the ACCOUNT. An operator reading a `can_login` column
        // would have concluded that thirteen of the fourteen duplicate groups in
        // production carried no login, and every one of them does.
        $decision = $plan['login_decision'];

        $this->line('Portal accounts:');
        $this->table(
            ['guardian', 'user', 'can sign in today', 'same account as keeper', 'only this school', 'merge will'],
            array_map(fn (array $row) => [
                "guardian#{$row['guardian_id']}",
                "user#{$row['user_id']}",
                $row['can_authenticate'] ? 'YES' : 'no',
                $row['same_user_as_keeper'] ? 'yes' : 'NO',
                // users.disabled_at is a property of the ACCOUNT. An account that
                // still backs live records elsewhere cannot be ended here without
                // taking another school's access with it, so the operator sees
                // where before, not after.
                $row['school_exclusive']
                    ? 'yes'
                    : 'NO — also '.implode(', ', array_map(
                        fn (int $id) => "school#{$id}",
                        $row['remaining_school_ids'],
                    )),
                match ($row['action']) {
                    'disable' => 'DISABLE this account',
                    'refuse' => 'refuse (pass --consolidate-login to disable it)',
                    'refuse (account is not school-exclusive)' => 'REFUSE — cannot be disabled here',
                    default => 'leave it alone',
                },
            ], $decision['donors']),
        );

        $this->line(sprintf(
            'Surviving account: user#%d — %s, %s.',
            $decision['keeper_user_id'],
            $decision['keeper_deliverable'] ? 'deliverable address' : 'NO DELIVERABLE ADDRESS',
            $decision['keeper_disabled'] ? 'currently disabled' : 'enabled',
        ));

        $this->line($decision['consolidating']
            ? 'The parent WILL be emailed fresh credentials for the surviving account'
                .($plan['applied'] ? '.' : ' when this is applied.')
            : 'No login is being ended, so no credentials will be sent.');

        $this->newLine();

        $this->line('Student links moved to the keeper: '.count($plan['pivot_moves']));
        if ($plan['pivot_moves'] !== []) {
            $this->table(
                ['student', 'from', 'relationship', 'is_primary', 'can_login'],
                array_map(fn (array $move) => [
                    "student#{$move['student_id']}",
                    "guardian#{$move['from_guardian_id']}",
                    $move['relationship'],
                    $move['is_primary'] ? 'yes' : 'no',
                    $move['can_login'] ? 'yes' : 'no',
                ], $plan['pivot_moves']),
            );
        }

        $this->line('Link collisions (both linked to the same student): '.count($plan['pivot_collisions']));
        if ($plan['pivot_collisions'] !== []) {
            $this->table(
                ['student', 'from', 'is_primary', 'can_login', 'resolution'],
                array_map(fn (array $c) => [
                    "student#{$c['student_id']}",
                    "guardian#{$c['from_guardian_id']}",
                    ($c['is_primary_before'] ? 'yes' : 'no').' → '.($c['is_primary_after'] ? 'yes' : 'no'),
                    ($c['can_login_before'] ? 'yes' : 'no').' → '.($c['can_login_after'] ? 'yes' : 'no'),
                    $c['resolution'],
                ], $plan['pivot_collisions']),
            );
        }

        $this->line('Other guardians demoted from primary: '.count($plan['primary_demotions']));
        if ($plan['primary_demotions'] !== []) {
            $this->table(
                ['student', 'demoted'],
                array_map(fn (array $d) => [
                    "student#{$d['student_id']}",
                    implode(', ', array_map(fn (int $id) => "guardian#{$id}", $d['guardian_ids'])),
                ], $plan['primary_demotions']),
            );
        }

        $this->line('Blank fields back-filled onto the keeper: '.count($plan['backfilled']));
        if ($plan['backfilled'] !== []) {
            $this->table(
                ['field', 'taken from'],
                array_map(fn (array $b) => [
                    $b['field'],
                    "guardian#{$b['from_guardian_id']}",
                ], $plan['backfilled']),
            );
        }

        $this->line('Guardian records soft-deleted: '
            .implode(', ', array_map(fn (int $id) => "guardian#{$id}", $plan['soft_deleted_ids'])));

        // Reported, not acted on: guardians.user_id is NOT NULL with cascadeOnDelete,
        // so deleting one of these users would hard-delete that person's guardian
        // records in every other school.
        $this->line($plan['orphaned_user_ids'] === []
            ? 'Users left backing no live guardian: none.'
            : 'Users left backing no live guardian anywhere (NOT touched): '
                .implode(', ', array_map(fn (int $id) => "user#{$id}", $plan['orphaned_user_ids'])));

        $this->newLine();
    }
}
