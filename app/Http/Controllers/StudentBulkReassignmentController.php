<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkReassignStudentsRequest;
use App\Models\Curriculum;
use App\Models\User;
use App\Services\CurriculumReassignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\CauserResolver;

/**
 * Move a whole class-cohort into a sibling arm in one action — every pupil in 9B to 9S.
 *
 * ── ALL OR NOTHING, AND THE ONE catch IS NOT A SAFETY NET ─────────────────────────────────────────
 * A half-applied cohort move is the worst outcome available here: some pupils in the new arm, some
 * in the old, no record of where the boundary fell, and an operator who cannot tell which. So the
 * batch is ONE `DB::transaction` and any throw inside it rolls every pupil back.
 *
 * Predictable failures — a stale episode, a mixed-cohort selection, a non-sibling destination, a
 * foreign uuid — are 422s raised by BulkReassignStudentsRequest BEFORE the transaction opens, and
 * nothing here catches them. The single `catch` around the batch boundary exists for exactly one
 * job: converting an EXCEPTIONAL rollback into an honest "nothing was moved" instead of a bare 500
 * that leaves the operator unsure whether half the class moved. It re-throws nothing it can handle,
 * it salvages no rows, and it must never grow into a general `try/catch` around the action body.
 *
 * THAT DISTINCTION IS LOAD-BEARING, because the neighbouring student controllers wrap their bodies
 * in `catch (\Throwable)` and that is a bug, not a convention: ValidationException is a Throwable, so
 * it gets swallowed and re-emitted as a generic 500, destroying the per-field 422 the screen exists
 * to produce. Do not "fix" this file to match the neighbours; the neighbours are what needs fixing.
 * Named explicitly because the pattern is one file away and looks like the house style.
 *
 * ── THE DESTINATION IS COMPUTED ONCE, NOT PER PUPIL ───────────────────────────────────────────────
 * The request's cohort lock guarantees every episode shares one `curriculum_id`, which is what makes
 * a single destination list correct for the whole batch. Without the lock this loop would be
 * applying one operator's choice to pupils for whom it was never a legal option.
 *
 * ── ATTRIBUTION FOLLOWS THE OPERATOR, NEVER THE ACTED-AS IDENTITY ─────────────────────────────────
 * `$request->user()` is the IMPERSONATED principal inside a sanctioned impersonation session. The
 * human answerable for the move is the operator, whom Impersonation pins on spatie's CauserResolver.
 * Same rule as StudentReassignmentController; `auth()->setUser()` is never touched here.
 */
class StudentBulkReassignmentController extends Controller
{
    public function __construct(
        private readonly CurriculumReassignmentService $reassignment,
    ) {}

    public function store(BulkReassignStudentsRequest $request): JsonResponse
    {
        $episodes = $request->resolvedEpisodes();
        $destination = $request->resolvedDestination();
        $causer = $this->causer($request);
        $reason = $request->input('reason');

        // CAPTURED BEFORE THE MOVE. The service soft-ends each episode and refreshes the row on the
        // way out, so reading the origin label afterwards would describe wherever the pupil ended
        // up — an audit line reading "Reassigned from 9S to 9S". The lock guarantees one origin for
        // the whole batch, so this is read once.
        $from = $this->describeCurriculum($episodes->first()?->curriculum);
        $to = $this->describeCurriculum($destination);
        $line = "Reassigned from {$from} to {$to}";

        // ── THE BATCH UUID ────────────────────────────────────────────────────────────────────────
        // One activity row per pupil, so an individual's history reads correctly on their own page —
        // a pupil moved in a batch of 24 should not have to be understood by reading 23 other
        // pupils' timelines. The shared uuid in each row's properties is what makes the N rows
        // recognisable afterwards as ONE operator action rather than N coincidences that happened to
        // share a timestamp.
        $batchId = (string) Str::uuid();

        try {
            DB::transaction(function () use ($episodes, $destination, $causer, $reason, $line, $batchId) {
                foreach ($episodes as $episode) {
                    $this->reassignment->reassign($episode, $destination, $causer, $reason);

                    // Written against the VACATED episode, because that is the row an operator looks
                    // at when asking why a pupil left a class. Inside the transaction so a rollback
                    // takes the audit rows with the writes they describe — an activity row for a
                    // move that did not happen is worse than no row at all.
                    activity()
                        ->performedOn($episode)
                        ->withProperties([
                            'reason' => $reason,
                            'batch_id' => $batchId,
                            'batch_size' => $episodes->count(),
                        ])
                        ->log($line);
                }
            });
        } catch (\Throwable $e) {
            // See the class docblock: this converts an exceptional rollback into an honest sentence.
            // It does not salvage rows and it does not widen to the action body.
            report($e);

            return response()->json([
                'message' => 'Nothing was moved. The reassignment failed partway through and every '
                    .'pupil was left in their current class. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => $episodes->count().' '.Str::plural('pupil', $episodes->count())
                ." reassigned from {$from} to {$to}",
            'audit_line' => $line,
            'batch_id' => $batchId,
            'moved' => $episodes->count(),
            'destination' => [
                'id' => $destination->uuid,
                'label' => $to,
            ],
        ]);
    }

    /**
     * "Year 8 S" — level then arm, with the stream when one is configured.
     *
     * Same assembly as StudentReassignmentController, and included for the same reason:
     * `class_level_arms` is UNIQUE on (class_level_id, arm_id, stream_id), so two arms in one level
     * can share a label and differ only by stream.
     */
    private function describeCurriculum(?Curriculum $curriculum): string
    {
        $arm = $curriculum?->classLevelArm;

        if ($arm === null) {
            return '—';
        }

        return implode(' ', array_filter([
            $arm->classLevel?->name,
            $arm->arm?->label,
            $arm->stream?->name,
        ]));
    }

    /**
     * The human answerable for the move — the operator, not the acted-as identity.
     */
    private function causer(Request $request): User
    {
        $causer = app(CauserResolver::class)->resolve();

        if ($causer instanceof User) {
            return $causer;
        }

        $user = $request->user();

        abort_if($user === null, 403);

        return $user;
    }
}
