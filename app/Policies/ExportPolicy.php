<?php

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

/**
 * Reference Policy for the Module authorization pattern:
 *  - controllers call $this->authorize(); the Policy owns record-level rules
 *  - Gate::before grants the team-less super_admin a bypass, so Policies only
 *    express the rules for ordinary users
 *  - School isolation is handled upstream by BelongsToSchool (a cross-School
 *    id never resolves), so a Policy only adds permission + ownership rules
 *
 * Finance Policies (InvoicePolicy, PaymentPolicy, …) follow this shape.
 */
class ExportPolicy
{
    /**
     * Download an export artifact: requires the export permission and that the
     * artifact belongs to the requesting user.
     */
    public function download(User $user, Export $export): bool
    {
        return $user->can('activity_log.export')
            && $export->user_id === $user->id;
    }
}
