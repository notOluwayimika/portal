<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReassignStudentRequest;
use App\Models\Curriculum;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Services\CohortSiblings;
use App\Services\CurriculumReassignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\CauserResolver;

/**
 * The human correction path for a placement the jobs got structurally right and operationally wrong:
 * move one pupil from 8B to 8S.
 *
 * ── WHY THIS IS A SEPARATE CONTROLLER, AND WHY IT HAS NO try/catch ────────────────────────────────
 * The neighbouring student controllers wrap their bodies in `catch (\Throwable)`. That is a bug, not
 * a house style: ValidationException is a Throwable, so it is caught and re-emitted as a 500 with a
 * generic message — which destroys the per-field 422 this screen exists to produce. An operator who
 * picks the wrong class would be told "something went wrong" instead of which rule they broke.
 *
 * So validation lives in ReassignStudentRequest and NOTHING here catches it. Do not "fix" this to
 * match the neighbours; the neighbours are what needs fixing. Named explicitly because the pattern
 * is one file away and looks like the convention.
 *
 * ── ATTRIBUTION FOLLOWS THE OPERATOR, NEVER THE ACTED-AS IDENTITY ─────────────────────────────────
 * `$request->user()` is the IMPERSONATED principal inside a sanctioned impersonation session — that
 * is the whole point of Impersonation, which sets the guard to the target so permission middleware
 * and FormRequests resolve as them. The human answerable for the move is the operator, whom the same
 * class pins on spatie's CauserResolver. So `ended_by_user_id` is read from the resolver and only
 * falls back to the guard outside a session. `auth()->setUser()` is never touched here.
 */
class StudentReassignmentController extends Controller
{
    public function __construct(
        private readonly CurriculumReassignmentService $reassignment,
    ) {}

    /**
     * The pupil's current placement and every class they may be moved into.
     *
     * The destination list IS the contract of this screen — see CohortSiblings on why the offer and
     * the guard must read one query.
     */
    public function show(StudentCurriculum $studentCurriculum): JsonResponse
    {
        return response()->json($this->payloadFor($studentCurriculum));
    }

    public function store(ReassignStudentRequest $request, StudentCurriculum $studentCurriculum): JsonResponse
    {
        $destination = $request->resolvedDestination();

        // CAPTURED BEFORE THE MOVE. The service soft-ends this episode and the row is refreshed on
        // the way out, so reading the origin label afterwards would describe wherever the pupil
        // ended up — an audit line that says "Reassigned from 8S to 8S".
        $from = $this->describeCurriculum($studentCurriculum->curriculum);

        $episode = $this->reassignment->reassign(
            $studentCurriculum,
            $destination,
            $this->causer($request),
            $request->input('reason'),
        );

        $line = "Reassigned from {$from} to {$this->describeCurriculum($destination)}";

        // The sentence, not just the column diff. The service's model events record WHAT changed;
        // this records what a human did and reads back without a join. Written against the VACATED
        // episode because that is the row an operator looks at when asking why a pupil left a class.
        activity()
            ->performedOn($studentCurriculum)
            ->withProperties(['reason' => $request->input('reason')])
            ->log($line);

        return response()->json(array_merge(
            [
                'message' => $line,
                'audit_line' => $line,
            ],
            $this->payloadFor($episode->fresh() ?? $episode),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(StudentCurriculum $episode): array
    {
        $destinations = CohortSiblings::for($episode);

        return [
            'episode' => [
                'id' => $episode->uuid,
                'status' => $episode->status?->value,
                // ONE WORD, DECIDED SERVER-SIDE. The stored value is `transferred` because the
                // pupil did not leave the school and `withdrawn` could not be reused; the word an
                // operator reads is "Reassigned". Membership status (students.status) keeps its own
                // "Transferred" and is a different column with a different enum — the collision is
                // resolved HERE, at the display layer, and nowhere else.
                'status_label' => $episode->status?->displayLabel(),
                'curriculum' => $this->describeCurriculum($episode->curriculum),
            ],
            // An empty list is a legitimate state, not an error: a year group with one arm has no
            // sibling to move into. The panel says so rather than showing an empty picker.
            'destinations' => $destinations->map(fn (Curriculum $curriculum) => [
                'id' => $curriculum->uuid,
                'label' => $this->describeCurriculum($curriculum),
            ])->values(),
        ];
    }

    /**
     * "Year 8 S" — level then arm, with the stream when one is configured.
     *
     * Assembled here so the picker, the audit line and the response message cannot word the same
     * class three different ways. Stream is included for the same reason ClassLevelArmProgression's
     * describe() includes it: `class_level_arms` is UNIQUE on (class_level_id, arm_id, stream_id), so
     * two arms in one level can share a label and differ only by stream.
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
     * The human answerable for the move — the operator, not the acted-as identity. See the class
     * docblock.
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
