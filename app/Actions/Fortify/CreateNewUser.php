<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * Public self-registration is disabled (see config/fortify.php): users are
     * provisioned by administrators. This path is intentionally fail-closed so
     * that re-enabling registration cannot silently recreate the previous
     * behaviour — creating a hardcoded `admin` in a hardcoded "Secondary School".
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        throw new \RuntimeException('Public self-registration is disabled; users are created by administrators.');
    }
}
