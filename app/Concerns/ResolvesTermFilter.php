<?php

namespace App\Concerns;

use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Models\Term;
use Illuminate\Http\Request;

/**
 * Shared term resolution for data-entry flows that historically only worked
 * against the active term. Requests without a term_id keep today's behavior
 * exactly; passing the uuid of a completed term surfaces the backdated
 * (BackfillPastTermJob-created) enrollments for retroactive entry.
 */
trait ResolvesTermFilter
{
    protected function resolveTermFilter(Request $request): ?Term
    {
        $uuid = $request->query('term_id');

        if ($uuid) {
            $term = Term::where('uuid', $uuid)
                ->whereHas('academicSession', fn($query) => $query->where('school_id', auth()->user()->school_id))
                ->first();

            abort_unless($term, 404, 'Term not found.');

            return $term;
        }

        return Term::where('status', TermStatusEnum::ACTIVE->value)->first();
    }

    /**
     * Which StudentCurriculum statuses count as "enrolled" for a term.
     * Backdated enrollments are created as 'promoted', and repeaters'
     * historical rows are 'repeated', so past terms accept those too.
     *
     * @return array<int, string>
     */
    protected function enrollmentStatusesFor(Term $term): array
    {
        if ($term->status === TermStatusEnum::COMPLETED) {
            return [
                StudentStatusEnum::ACTIVE->value,
                StudentStatusEnum::PROMOTED->value,
                StudentStatusEnum::REPEATED->value,
            ];
        }

        return [StudentStatusEnum::ACTIVE->value];
    }
}
