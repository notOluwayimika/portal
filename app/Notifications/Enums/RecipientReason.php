<?php

namespace App\Notifications\Enums;

/**
 * Why a person is a recipient — stored per recipient row.
 *
 * Not decoration. For ROLE-derived recipients the answer is genuinely unobvious
 * to the person receiving it ("why am I being told about a credit note?"), and
 * without it the only way to answer is to re-run the resolver against a
 * permission set that may have changed since.
 */
enum RecipientReason: string
{
    case DIRECT = 'direct';
    case ROLE = 'role';
    case RELATIONSHIP = 'relationship';
    case WATCHER = 'watcher';
}
