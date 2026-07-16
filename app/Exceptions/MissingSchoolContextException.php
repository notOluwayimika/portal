<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a School-scoped model is queried with no active School context and
 * fail-closed scoping is enabled (§5.5). The sanctioned escapes are
 * Model::withoutSchoolScope() (explicit, greppable) and, off-request,
 * ActiveSchool::runFor().
 */
class MissingSchoolContextException extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct(sprintf(
            'Queried the School-scoped model [%s] with no active School context. '
            .'Establish context via SetSchoolContext (request) or ActiveSchool::runFor() (off-request).',
            $model,
        ));
    }

    /**
     * Render as a clean "select a school" response rather than a 500.
     */
    public function render(Request $request)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'No active school selected.'], 409);
        }

        return redirect()->route('school.select');
    }
}
