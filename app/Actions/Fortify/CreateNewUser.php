<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();
        $school = School::firstOrCreate(['slug' => 'secondary-school'], ["name" => "Secondary School", "slug" => Str::slug("Secondary School")]);
        $user = $school->users()->create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
        $user->assignRole('admin');
        return $user;
    }
}
