<?php

namespace App\Actions\Fortify;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Fortify's profile-update action. Recreated after e5fb992 deleted it while
 * FortifyServiceProvider still wired updateUserProfileInformationUsing().
 *
 * Uses the app's current data model (first_name / last_name, not Laravel's
 * default `name`) via the shared ProfileValidationRules, and mirrors
 * Settings\ProfileController::update — changing the email resets
 * email_verified_at. LogsActivity audits the change on save. (The app's own
 * settings/profile route uses ProfileController directly; this action backs
 * Fortify's built-in /user/profile-information route so the wiring is no longer
 * a latent 500.)
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    use ProfileValidationRules;

    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, $this->profileRules($user->id))
            ->validateWithBag('updateProfileInformation');

        $emailChanged = isset($input['email']) && $input['email'] !== $user->email;

        $user->forceFill([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();
    }
}
