<?php

namespace App\Finance\Enums;

/** The maker-checker lifecycle of a fee-schedule change request. The schedule activates ONLY on approval. */
enum FeeScheduleChangeStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
