<?php

/**
 * `bin/quality` must agree with itself about how many steps it has.
 *
 * WHY THIS EXISTS. The gate prints `[N/TOTAL]` from a single hardcoded TOTAL in step(), while the
 * actual total is however many times step() is called. Nothing tied those two numbers together, and
 * a 14 → 15 → 14 round trip on the branch that added this proved what that costs: the printf and ten
 * prose sites had to be moved by hand, six of them were missed on the first pass with no red
 * anywhere in the repo, and the whole set had to be moved back when the step that caused it was
 * split out. A number that only a human can check is a number that drifts. The split-out step
 * has since landed on its own and the count is 15 for real.
 *
 * The assertion is RELATIONAL, not a pinned count — it held at 14, holds at 15, and will hold at
 * whatever comes next. It is the one piece of that branch's gate work that survives on its own.
 *
 * WHAT IT DOES NOT COVER, and this matters more than what it does. It catches the FILE disagreeing
 * with ITSELF. It cannot catch documentation drift — the ADRs, tickets, briefs and test comments
 * that name a step by number ("the suite is step 15", "fifteen steps guarantee…") are prose in
 * other files, and every one of those has to be moved by hand. Do not read a green here as "the
 * step count is consistent across the repo"; read it as "the script's own two numbers still match".
 * The wider half stays unmechanised.
 */
it('prints the same step total that it actually runs', function () {
    $script = file_get_contents(dirname(__DIR__, 3).'/bin/quality');

    // The printed total: the ONE literal in step()'s printf.
    expect(preg_match('/\[%d\/(\d+)\]/', $script, $printed))->toBe(1);

    // The real total: every `step "…"` invocation, at the start of a line — the definition itself
    // and any prose mentioning step() are not counted.
    //
    // THE LEADING \s* IS THE WHOLE DIFFERENCE BETWEEN THIS AND A FALSE GREEN. Anchored at column
    // zero, an INDENTED call — the natural shape for a step inside a conditional, i.e. one that
    // runs only under a flag — is not counted. The count then comes out one SHORT, which AGREES
    // with an unmoved printf, and the test passes while the gate prints `[15/14]` at runtime. That
    // is precisely the drift this exists to catch, and the floor below does not see it (14 clears
    // 10). Today every call is top-level; the anchor must not depend on that staying true.
    $actual = preg_match_all('/^\s*step "/m', $script);

    // Non-vacuity first — a regex that stopped matching would otherwise report 0 == 0 if the
    // printf literal were also gone, and a broken pattern would look like agreement.
    expect($actual)->toBeGreaterThan(10);

    expect((int) $printed[1])->toBe(
        $actual,
        "bin/quality prints [%d/{$printed[1]}] but calls step() {$actual} times — a step was added or "
        .'removed without moving the total. Every other place that names a step BY NUMBER (ADRs, '
        .'tickets, test comments) is prose this test cannot see: move those by hand.'
    );
});
