<?php

namespace App\Services;

/**
 * More than one live guardian in the school shares the submitted phone number, and
 * nothing else in the submission singles one of them out.
 *
 * EXTENDS ImportConflictException on purpose. The spreadsheet import catches that one
 * class and turns it into a failed row with the exception's own message
 * (GuardianImportService), so an ambiguous row keeps behaving like every other
 * unresolvable row rather than becoming a 500 — no change to the import, no second
 * catch to remember. The interactive callers catch this subclass FIRST, because their
 * message belongs on the `phone` field rather than on `email`.
 */
class AmbiguousPhoneMatchException extends ImportConflictException {}
