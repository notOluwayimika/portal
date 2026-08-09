#!/usr/bin/env php
<?php

/**
 * Boundary lint gates (§17.2 + §17.1 rule 4) — the grep-enforceable half of the
 * M1.5a enforcement floor. Companion to bin/ci-authz-lint.php (§17.2 rule 1,
 * commented-out authorization), which already exists and stays separate.
 *
 * Rules:
 *   school-id-fallback-literal  `?? $user->school_id` anywhere in app/
 *                               (Constitution 13). HARD — zero occurrences
 *                               remain, so there is no baseline for it.
 *   school-id-fallback-context  a `$user->school_id` / `->user()->school_id`
 *                               occurrence anywhere in app/ — Constitution 13 is
 *                               application-wide, so the guarded form of the
 *                               fallback is banned everywhere, not just in the
 *                               context primitives. Known temporary exceptions
 *                               are BASELINED (see boundary-lint-baseline.txt):
 *                               they expire when users.school_id is dropped
 *                               (§5.3, §7.1 — Phase 1C contract step).
 *   decimal-money-cast          `decimal:` cast on a money-named attribute
 *                               (Constitution 10). Deliberately app-wide, NOT
 *                               Finance-scoped: the known upcoming money columns
 *                               live on legacy models (e.g. Scholarship, Ph2).
 *                               The name pattern excludes the academic
 *                               score/weight decimals.
 *   finance-table-outside-finance  a `finance_*` table-name literal outside
 *                               app/Finance (Constitution 3): finance_ tables are
 *                               Finance-owned. Known temporary exception baselined
 *                               — ModuleClassificationService reads finance_ tables
 *                               until Ph2's FinanceModuleStatus contract
 *                               (ADR 0030) replaces it.
 *   force-create-finance-tests  `forceCreate(` in Finance tests — bypasses
 *                               MoneyCast. HARD (no Finance tests exist yet).
 *   finance-escape-hatches      withoutGlobalScope / withoutSchoolScope /
 *                               withTrashed / withoutTrashed / SoftDeletingScope /
 *                               ->hasRole( / auth()->setUser / DB::table( inside
 *                               app/Finance/ (§17.1 rule 4 — method calls, which
 *                               arch tests cannot see). HARD; inert until
 *                               app/Finance exists, live from its first commit.
 *
 *                               THE SOFT-DELETE TOKENS ARE NOT A SIXTH RULE. They
 *                               are the SAME rule under a different name:
 *                               `withTrashed()` IS
 *                               `withoutGlobalScope(SoftDeletingScope::class)` —
 *                               Laravel's own SoftDeletes trait implements it that
 *                               way. The behaviour was forbidden from the first
 *                               commit; only the TOKEN escaped, because this lint
 *                               greps tokens and not semantics. That is the general
 *                               lesson and it is worth stating where the next
 *                               reader will meet it: a token-grep lint cannot see a
 *                               method that reaches the same forbidden behaviour
 *                               under a different name, so every alias of a banned
 *                               call has to be enumerated by hand or the rule has a
 *                               hole shaped exactly like the alias.
 *
 * Like the sibling ratchets, the baseline may only shrink: CI fails on any NEW
 * occurrence; removing a baselined line is reported as progress.
 *
 * Usage:
 *   php bin/ci-boundary-lint.php            # check (CI): exit 1 on new findings
 *   php bin/ci-boundary-lint.php generate   # (re)write the baseline
 */
$root = dirname(__DIR__);
$baselinePath = $root.'/boundary-lint-baseline.txt';
$mode = $argv[1] ?? 'check';

/** @return array<int, array{0: string, 1: string}> [[relativePath, line], ...] */
function phpLines(string $dir, string $root): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
            $out[] = [$rel, $line];
        }
    }

    return $out;
}

function isComment(string $line): bool
{
    $t = ltrim($line);

    return str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '#');
}

$app = phpLines($root.'/app', $root);
$tests = phpLines($root.'/tests', $root);

$found = [];
$add = function (string $rule, string $rel, string $line) use (&$found) {
    $found[$rule."\t".$rel."\t".trim($line)] = true;
};

foreach ($app as [$rel, $line]) {
    if (isComment($line)) {
        continue;
    }

    // school-id-fallback-literal — Constitution 13, anywhere in app/.
    if (str_contains($line, '?? $user->school_id') || str_contains($line, '??$user->school_id')) {
        $add('school-id-fallback-literal', $rel, $line);
    }

    // school-id-fallback-context — guarded fallback reads, app-wide (Constitution 13).
    if (preg_match('/(\$user->school_id|->user\(\)->school_id)/', $line)) {
        $add('school-id-fallback-context', $rel, $line);
    }

    // decimal-money-cast — Constitution 10, app-wide by design.
    if (preg_match('/[\'"]\w*(amount|fee|price|balance|kobo|minor|money|debit|credit)\w*[\'"]\s*=>\s*[\'"]decimal/i', $line)) {
        $add('decimal-money-cast', $rel, $line);
    }

    // finance-table-outside-finance — Constitution 3. finance_* tables are
    // Finance-owned; a `finance_<table>` literal anywhere outside app/Finance/ is
    // a boundary violation. (Renamed from the fee_* marker at the template freeze.)
    if (! str_starts_with($rel, 'app/Finance/') && preg_match('/[\'"]finance_\w+[\'"]/', $line)) {
        $add('finance-table-outside-finance', $rel, $line);
    }

    // finance-escape-hatches — §17.1 rule 4, method calls inside app/Finance/.
    // withTrashed/withoutTrashed/SoftDeletingScope are the SAME rule as
    // withoutGlobalScope, not a new one — see the header. They are enumerated
    // separately only because the match is on tokens, not on behaviour.
    if (str_starts_with($rel, 'app/Finance/')
        && preg_match('/(withoutGlobalScopes?\(|withoutSchoolScope\(|withTrashed\(|withoutTrashed\(|SoftDeletingScope|->hasRole\(|auth\(\)->setUser\(|DB::table\()/', $line)) {
        $add('finance-escape-hatches', $rel, $line);
    }

    // halting-event-arrow-fn — Laravel's creating/updating/saving/deleting events
    // are HALTING (dispatched via until()): a listener returning a non-null value
    // silently stops the rest of the chain. An arrow fn `fn(...) => expr` always
    // returns `expr`, so registering one for a halting event is a latent
    // chain-halt (this is exactly how AddUuid halted BelongsToSchool's auto-fill).
    // Register halting-event listeners with a block closure that returns nothing.
    if (preg_match('/(static::|->)(creating|updating|saving|deleting)\(\s*fn\b/', $line)) {
        $add('halting-event-arrow-fn', $rel, $line);
    }
}

foreach ($tests as [$rel, $line]) {
    if (isComment($line)) {
        continue;
    }

    // force-create-finance-tests — MoneyCast bypass in Finance tests.
    if (preg_match('#tests/.*Finance#i', $rel) && str_contains($line, 'forceCreate(')) {
        $add('force-create-finance-tests', $rel, $line);
    }
}

// approval-seam-missing — every Finance maker (an app/Finance/Actions/Submit*.php) must route its
// "does this submission need a second signature" decision through the ApprovalRequirement seam
// (ADR 0051). That seam is the ONE place the maker-checker requirement becomes configurable; a Submit
// action that does not INVOKE it has an unconditional decision hard-wired back at the call site —
// exactly the ten-places drift the seam exists to erase. Keyed on the CALL (`ApprovalRequirement::for(`)
// on a LIVE line, not the mere token and not a comment: deleting only the branch (leaving a stale `use`)
// trips the rule, and so does commenting the call out (the authz-rule-15 hole, closed here too). The set
// of Submit actions is enumerated from the filesystem, never hardcoded — a new Submit*.php is covered the
// moment it lands. Pure enforcement: ZERO baseline entries.
$submitActions = glob($root.'/app/Finance/Actions/Submit*.php') ?: [];
foreach ($submitActions as $path) {
    $rel = ltrim(str_replace($root, '', $path), '/');
    $calls = false;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (! isComment($line) && str_contains($line, 'ApprovalRequirement::for(')) {
            $calls = true;
            break;
        }
    }
    if (! $calls) {
        $add('approval-seam-missing', $rel, 'does not call ApprovalRequirement::for() — the maker-checker seam (ADR 0051)');
    }
}

// school-context-guard-missing — Constitution 13, enforced on every finance maker-checker Action.
//
// WHAT IT CATCHES AND WHY IT IS NOT LEFT TO SchoolScope. The scope filters reads by the active
// School, which is isolation when the context is right and something far worse when it is wrong:
// every read finds nothing, and an action that reads nothing does not refuse — it succeeds on an
// empty set. `config/rbac.php:78-81` reads RBAC_FAIL_CLOSED_MODELS from the environment and it is
// EMPTY everywhere, so the scope is fail-OPEN for every model. The refusal has to be explicit, so
// this rule requires it to be present.
//
// THE STRONG CALL IS THE DEFAULT. `SchoolContext::assertOwns(` refuses a null context AND a record
// belonging to another School. `SchoolContext::require(` refuses only the first, and is permitted in
// exactly ONE file, named below with its reason — an allowlist of one, argued in place. A rule that
// accepted either call anywhere could not tell the two apart, which is the whole reason the helper
// has two names rather than one nullable argument.
//
// SCOPE STOPS AT Submit/Approve/Reject IN app/Finance/Actions, and that boundary is deliberate:
// those fifteen are the maker-checker governance surface, where the failure mode is an act that
// reports success having done nothing. GenerateInvoice, RecordAccountPayment, PostOpeningBalanceBatch
// and CreateFeeSchedule guard in bespoke form on money-writing paths; converting them is a separate
// decision, argued on its own, and widening this rule to reach them would smuggle it in. Any Action
// added to those three prefixes is covered the moment it lands — the set is globbed, never listed.
// Pure enforcement: ZERO baseline entries.
$guardStrong = 'SchoolContext::assertOwns(';
$guardWeak = 'SchoolContext::require(';

// THE ONE FILE THAT MUST CALL require() — and "must", not "may", is the whole correction.
//
// A first version of this rule merely PERMITTED require() here. That check was ONE-SIDED and the
// watched red proved it: stripping require() went red, but replacing it with an unconditional
// assertOwns() stayed GREEN — and that swap is a real defect, because a `create` change names no
// policy and assertOwns() would be handed nothing to own. A rule that cannot object to the wrong
// call in the one file it singles out says less than its name.
//
// So the allowlist is an OBLIGATION. SubmitDiscountPolicyChange's `create` kind names no policy —
// the action itself refuses a create that does — so on that path there is no pre-existing
// school-owned record for the act to belong to. The change row's own school_id is stamped from the
// context, and the only school-owned read is gated on a non-null target. The context is therefore
// the WHOLE school-sensitive surface there, and require() closes all of it: a complete guard over a
// smaller surface, not a partial guard over the same one. It also calls assertOwns() when a target
// IS named, which this rule neither requires nor forbids.
$mustUseWeak = ['app/Finance/Actions/SubmitDiscountPolicyChange.php'];

$governanceActions = array_merge(
    glob($root.'/app/Finance/Actions/Submit*.php') ?: [],
    glob($root.'/app/Finance/Actions/Approve*.php') ?: [],
    glob($root.'/app/Finance/Actions/Reject*.php') ?: [],
);

foreach ($governanceActions as $path) {
    $rel = ltrim(str_replace($root, '', $path), '/');
    $strong = false;
    $weak = false;

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (isComment($line)) {
            continue;
        }
        if (str_contains($line, $guardStrong)) {
            $strong = true;
        }
        if (str_contains($line, $guardWeak)) {
            $weak = true;
        }
    }

    if (in_array($rel, $mustUseWeak, true)) {
        // The obligation, not a permission: this file's create path has nothing to own, so the
        // context refusal is the only guard available and it must be present.
        if (! $weak) {
            $add(
                'school-context-guard-missing',
                $rel,
                'does not call SchoolContext::require() on a live line — its create kind names no policy, so the context refusal is the only guard that path can carry (Constitution 13)'
            );
        }

        continue;
    }

    if (! $strong) {
        $add(
            'school-context-guard-missing',
            $rel,
            'does not call SchoolContext::assertOwns() on a live line — a finance governance act with no School-context refusal (Constitution 13)'
        );
    }
}

// approval-seam-count — the Finance Submit actions must stay in lockstep with the maker abilities the
// maker-checker convention derives: exactly one Submit*.php per finance `*_SUBMIT` Permission case. A new
// maker permission with no Submit action (or a Submit action with no maker ability) is coverage drift the
// per-file rule above cannot see. Counts are STATIC — grep the enum, no Laravel boot. NOTE: this is the
// count of distinct MAKERS, not DutySeparation::pairs(): pairs() double-counts (each maker yields an
// approve AND a reject checker → 8 finance pairs for 4 makers), so the invariant is makers == Submit files.
$financeSubmitAbilities = preg_match_all(
    '/case\s+FINANCE_[A-Z_]*SUBMIT\s*=/',
    (string) file_get_contents($root.'/app/Enums/Permission.php')
);
if ($financeSubmitAbilities !== count($submitActions)) {
    $add(
        'approval-seam-count',
        'app/Enums/Permission.php',
        "finance *_SUBMIT abilities ({$financeSubmitAbilities}) != Submit* actions (".count($submitActions).') — ADR 0051 seam-coverage drift'
    );
}

$found = array_keys($found);
sort($found);

if ($mode === 'generate') {
    $header = <<<'TXT'
# boundary-lint baseline — intentional, TEMPORARY exceptions. May only shrink.
#
# school-id-fallback-context entries expire when users.school_id is dropped
#   (§5.3/§7.1 — after the rbac.single_source_access parity gate; ActiveSchool's
#   guarded fallback and ActivitySchoolResolver's user fallback go with it).
#   NOTE on the SuperAdmin/AdminController entry: that one is a legacy-column
#   MAINTENANCE WRITE (keeping the retained expand/contract users.school_id
#   pointing at a School the user can access), NOT a context-read fallback —
#   a rule true-positive on the column's existence rather than a Constitution 13
#   violation in logic. Same expiry (the users.school_id drop); when burning down
#   this baseline, delete that code with the column — there is no fallback logic
#   to remove there.
# finance-table-outside-finance entries expire when Ph2's FinanceModuleStatus
#   contract (ADR 0030) replaces ModuleClassificationService's direct finance_* reads.
# halting-event-arrow-fn has ZERO baseline entries — every halting-event listener
#   uses a block closure, so the rule is pure enforcement (no exceptions).
# approval-seam-missing / approval-seam-count have ZERO baseline entries — every Finance
#   Submit action calls the ApprovalRequirement seam and the maker/action counts match
#   (ADR 0051), so both rules are pure enforcement (no exceptions).
# school-context-guard-missing has ZERO baseline entries — every Finance Submit/Approve/Reject
#   action calls SchoolContext::assertOwns() on a live line, with require() permitted in
#   SubmitDiscountPolicyChange alone and the reason written beside the allowlist
#   (Constitution 13). Pure enforcement (no exceptions).

TXT;
    file_put_contents($baselinePath, $header.($found ? implode("\n", $found)."\n" : ''));
    fwrite(STDERR, 'boundary-lint: wrote '.count($found)." baseline entries to boundary-lint-baseline.txt\n");
    exit(0);
}

$baseline = is_file($baselinePath)
    ? array_values(array_filter(array_map('rtrim', file($baselinePath)), fn ($l) => $l !== '' && ! str_starts_with($l, '#')))
    : [];

$new = array_values(array_diff($found, $baseline));
$fixed = array_values(array_diff($baseline, $found));

if ($new) {
    fwrite(STDERR, "\nboundary-lint: ".count($new)." NEW boundary violation(s):\n");
    foreach ($new as $n) {
        fwrite(STDERR, '  '."\u{2717}".' '.str_replace("\t", '  ', $n)."\n");
    }
    exit(1);
}

// SHRINK-LOCK. This block previously only WARNED and still exited 0, so the baseline
// could sit above the true count indefinitely — slack a future regression can hide in.
// Audited and fixed 2026-07-20, after the identical defect was found in ci-authz-lint:
// a stale baseline entry was planted, the lint printed "removed (good)" and exited 0.
// It now FAILS, matching the tests, tsc and authz ratchets.
if ($fixed) {
    fwrite(STDERR, "\nboundary-lint: ".count($fixed)." baselined exception(s) removed (good!) — lock it in by removing them from boundary-lint-baseline.txt:\n");
    foreach ($fixed as $f) {
        fwrite(STDERR, '  - '.str_replace("\t", '  ', $f)."\n");
    }
    fwrite(STDERR, "  regenerate: php bin/ci-boundary-lint.php generate\n");
    exit(1);
}

fwrite(STDERR, 'boundary-lint: OK — no new boundary violations ('.count($found)." known temporary exceptions).\n");
exit(0);
