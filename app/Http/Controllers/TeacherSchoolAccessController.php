<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncTeacherSchoolsRequest;
use App\Models\School;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class TeacherSchoolAccessController extends Controller
{
    /**
     * Replace a teacher's extra school access (school_user pivot) with the
     * given set. The home school (teachers.school_id) is never revoked and
     * needs no pivot row — login falls back to users.school_id. Grants made
     * by admins of schools the acting admin cannot access are preserved.
     */
    public function sync(SyncTeacherSchoolsRequest $request, Teacher $teacher)
    {
        abort_unless($teacher->user, 422, 'This teacher has no login account, so school access cannot be managed.');

        $manageableIds = $request->user()->accessibleSchoolIds();

        $target = School::whereIn('uuid', $request->validated('schools'))->get();

        abort_if(
            $target->contains(fn (School $school) => !$manageableIds->contains((int) $school->id)),
            403,
            'You can only grant access to schools you have access to.'
        );

        $user = $teacher->user;

        DB::transaction(function () use ($teacher, $user, $target, $manageableIds) {
            $current = $user->schools()->get();

            foreach ($target as $school) {
                if ((int) $school->id === (int) $teacher->school_id) {
                    continue;
                }

                if (!$current->contains('id', $school->id)) {
                    $user->grantSchoolAccess($school, 'teacher');
                }
            }

            foreach ($current as $school) {
                if ((int) $school->id === (int) $teacher->school_id) {
                    continue;
                }

                if (!$manageableIds->contains((int) $school->id)) {
                    continue;
                }

                if (!$target->contains('id', $school->id)) {
                    $user->revokeSchoolAccess($school, 'teacher');
                }
            }
        });

        return Response::success('Teacher school access updated.');
    }
}
