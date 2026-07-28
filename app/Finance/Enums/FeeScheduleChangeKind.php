<?php

namespace App\Finance\Enums;

/**
 * The two governed acts on a published fee schedule — both proposed by the Head, approved by the ED.
 * There is deliberately no `create`: authoring a draft is ordinary work under finance.fee-schedule.manage,
 * and no `amend`: an active schedule's items are frozen (re-pricing is a fresh draft + a `publish`).
 */
enum FeeScheduleChangeKind: string
{
    case Publish = 'publish';
    case Retire = 'retire';
}
