<?php

namespace App\Finance\Enums;

/**
 * The lifecycle of a fee schedule (S1). A schedule is authored as a `draft` (items freely editable),
 * `active` once published (items frozen; at most one active per school/term/class level), and moves to
 * `superseded` when re-priced or `retired` when withdrawn. Money is never mutated — only `status`
 * moves — mirroring the credit-note / void-request treatment.
 */
enum FeeScheduleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';
    case Retired = 'retired';
}
