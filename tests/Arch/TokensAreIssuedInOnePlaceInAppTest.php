<?php

/*
 * ONE TOKEN ISSUER IN app/, AND THE GATE THAT KEEPS IT ONE.
 *
 * ── THE INVARIANT THIS PROTECTS, WHICH IS NOT ABOUT TOKENS ─────────────────────────────────────
 *
 * `ActiveSchool::id()` resolves session → the token's `school_id` → the legacy `users.school_id`
 * fallback (Constitution 13; the fallback is baselined under ADR 0042). For API traffic the fallback
 * is UNREACHABLE — but only because every token that exists names a school, and that is true only
 * because ONE place mints them and that place either stamps the school or refuses to mint at all
 * (`AuthenticationController`: multi-school → `Auth::logout()` and 409 with no token issued).
 *
 * A second issuer anywhere in `app/` would end that quietly. It would not fail; it would produce a
 * token with a NULL `school_id`, `ActiveSchool::id()` would fall through to `users.school_id`, and a
 * request would answer for the wrong school with nothing red anywhere. That is the exact shape this
 * repository keeps paying for: a correctness rule whose violation emits no signal propagates by
 * memory, and memory does not propagate.
 *
 * So the rule is a gate rather than a sentence in a docblock.
 *
 * ── SCOPE IS app/ AND IT IS IN THE NAME ───────────────────────────────────────────────────────
 *
 * `tests/` mints its own tokens and always will — `FinanceApiAcceptanceTest` has three, and they are
 * legitimate. The claim is about PRODUCTION issuance, so the scope is `app/` and the arm says so
 * rather than leaving a reader to infer it from the assertion.
 *
 * ── FOUR SPELLINGS, BECAUSE ONE IS A DENOMINATOR NOBODY MEASURED ──────────────────────────────
 *
 * `createToken(` is how Sanctum tokens are usually minted and it is NOT the only way:
 * `PersonalAccessToken::create(…)`, `$user->tokens()->create(…)` and `new PersonalAccessToken` all
 * produce one and none of them match that pattern. A matcher covering only the common spelling would
 * report clean over the exact change that breaks the invariant. Measured 2026-09-02: across the repo
 * (excluding vendor/node_modules) all four patterns together find FOUR occurrences — one in `app/`,
 * three in `tests/` — and no raw insert into `personal_access_tokens` anywhere.
 *
 * ── THE ALLOWLIST MAY ONLY SHRINK, AND THE MESSAGE SAYS SO ────────────────────────────────────
 *
 * Borrowed from the collation register's discipline. A gate whose failure message reads "add
 * yourself to the list" is a gate that removes itself the first time somebody legitimately needs a
 * second issuer — green, and the invariant gone. The failure text names the fix as ROUTING THROUGH
 * the existing issuer, and the list's size is pinned so growing it is a deliberate edit to this file
 * with a reviewer looking at it.
 */

use Illuminate\Support\Str;

uses()->group('arch');

/** Every way a Sanctum token can be minted. Recognise broadly; the allowlist judges. */
function tokenIssuancePatterns(): array
{
    return [
        'createToken(',
        'PersonalAccessToken::create',
        '->tokens()->create',
        'new PersonalAccessToken',
    ];
}

/** The ONLY file in app/ permitted to mint one. This list may shrink; it may not grow. */
function tokenIssuanceAllowed(): array
{
    return ['app/Http/Controllers/Api/AuthenticationController.php'];
}

/** @return list<string> every .php under app/ */
function tokenIssuanceAppFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));

    foreach ($iterator as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** @return list<array{file: string, pattern: string}> */
function tokenIssuanceScan(array $files): array
{
    $hits = [];

    foreach ($files as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            continue;
        }

        foreach (tokenIssuancePatterns() as $pattern) {
            if (str_contains($contents, $pattern)) {
                $hits[] = [
                    'file' => str_replace(dirname(__DIR__, 2).'/', '', $path),
                    'pattern' => $pattern,
                ];
            }
        }
    }

    return $hits;
}

it('mints Sanctum tokens in exactly one place in app/, and says how many files it read', function () {
    $files = tokenIssuanceAppFiles();

    // THE DENOMINATOR IS ASSERTED FIRST. A zero-findings assertion is satisfied perfectly by a scan
    // that read nothing, and this arm has no legitimate in-scope occurrence to act as a witness —
    // the one file it expects to find is the one it excludes. A LITERAL floor, never a count derived
    // from the scan, which would assert that the scan equals itself.
    expect(count($files))->toBeGreaterThan(200);

    $offenders = array_values(array_filter(
        tokenIssuanceScan($files),
        fn (array $hit) => ! in_array($hit['file'], tokenIssuanceAllowed(), true),
    ));

    // POSITIVE FORM, because Pest discards the custom message under `->not->` (the negated-message
    // gate in this suite exists for exactly that).
    expect($offenders)->toBe([], implode("\n", [
        'A second Sanctum token issuer appeared in app/.',
        '',
        'THE FIX IS TO ROUTE THROUGH Api\AuthenticationController, NOT TO ADD AN ENTRY BELOW.',
        '',
        'Every token that exists must name a school, or ActiveSchool::id() falls through to the',
        'users.school_id fallback and a request answers for the wrong school with nothing red.',
        'AuthenticationController is the one place that guarantees it: it stamps school_id on the',
        'token it issues, and on the multi-school branch it refuses to issue one at all.',
        '',
        'A token minted anywhere else carries a NULL school_id and breaks that silently.',
        '',
        'Offenders: '.json_encode($offenders),
    ]));
});

it('keeps the allowlist at one entry — it may shrink, it may not grow', function () {
    // PINNED AS A VALUE, not derived. Without this the gate above is satisfied by adding the new
    // issuer to the list, which is how a rule removes itself while the suite stays green.
    expect(tokenIssuanceAllowed())->toBe(['app/Http/Controllers/Api/AuthenticationController.php']);
});

it('never shrinks the allowlist to zero, because at zero the gate has no witness', function () {
    // TWO RULES THAT ARE EACH RIGHT AND COLLIDE AT THE BOUNDARY.
    //
    // "The allowlist may only shrink" is correct and it is what stops the gate being removed by
    // someone adding themselves to it. Taken to its limit it removes something else: the gate's only
    // WITNESS.
    //
    // A FIND-THE-THING gate proves itself by finding something. A FORBID-EVERYTHING gate has exactly
    // one legitimate match in the whole scope — the permitted file — and nothing else it is allowed
    // to match. At one entry, the known-positive arm below can assert the matcher finds it, so a
    // typo'd pattern or a scan of the wrong directory reds. At ZERO entries there is nothing the
    // matcher may legitimately find, and a broken matcher is indistinguishable from a clean
    // repository — not merely in practice, but IN PRINCIPLE. The gate becomes unverifiable.
    //
    // So the rule is: **it may shrink to ONE, and no further.** If the last entry ever legitimately
    // goes — the issuer moves out of app/, or token issuance stops entirely — the known-positive arm
    // must be replaced by a PLANTED fixture in the same commit, so the matcher keeps a witness.
    //
    // ITS OWN ARM, NOT A LINE INSIDE THE PIN ABOVE. Someone editing the allowlist to `[]` edits the
    // pin's expected value in the same motion and sees it go green again; this arm still reds. A
    // floor asserted beside the thing it constrains is a floor that moves with it.
    //
    // Written at length because the person removing the final entry will be doing something that
    // LOOKS like tightening, and every instinct will say the gate is getting stricter.
    expect(count(tokenIssuanceAllowed()))->toBeGreaterThan(0);
});

it('finds the one permitted issuer, so the matcher is not silently blind', function () {
    // THE KNOWN POSITIVE. Without it a matcher that matches NOTHING — a typo in a pattern, a scan
    // over the wrong directory — passes the arm above with an empty offender list. Broken-closed and
    // broken-open look identical from a green.
    $all = tokenIssuanceScan(tokenIssuanceAppFiles());

    expect(array_column($all, 'file'))->toContain('app/Http/Controllers/Api/AuthenticationController.php');
});

it('matches every spelling a token can be minted with, not just the common one', function (string $body, string $why) {
    $path = sys_get_temp_dir().'/token_issue_'.Str::random(12).'.php';
    file_put_contents($path, $body);

    try {
        expect(tokenIssuanceScan([$path]))->not->toBe([]);
    } finally {
        @unlink($path);
    }
})->with([
    'createToken' => ['<?php $u->createToken("x");', 'the common one'],
    'static create' => ['<?php PersonalAccessToken::create(["name" => "x"]);', 'bypasses the trait'],
    'relation create' => ['<?php $u->tokens()->create(["name" => "x"]);', 'bypasses the helper'],
    'direct construction' => ['<?php $t = new PersonalAccessToken;', 'bypasses everything'],
]);
