<?php

namespace App\Finance\Enums;

/** The three governed acts on the discount catalog — all proposed by the Head, approved by the ED. */
enum DiscountPolicyChangeKind: string
{
    case Create = 'create';
    case Amend = 'amend';
    case Retire = 'retire';
}
