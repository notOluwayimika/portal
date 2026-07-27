<?php

namespace App\Finance\Enums;

/** The maker-checker lifecycle of a change request. The catalog changes ONLY on approval. */
enum DiscountPolicyChangeStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
