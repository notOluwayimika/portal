<?php

namespace App\Finance\Enums;

/** The lifecycle of a discount policy. Money/identity are immutable; only status moves. */
enum DiscountPolicyStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Retired = 'retired';
}
