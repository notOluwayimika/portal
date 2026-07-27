<?php

namespace App\Finance\Enums;

/** How a discount is expressed: a fixed naira amount, or a percentage of the discountable charges. */
enum DiscountBasis: string
{
    case Amount = 'amount';
    case Percent = 'percent';
}
