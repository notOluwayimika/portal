# TICKET — a negated expectation can be narrowed by an argument the gate does not count

**Status:** open. **Zero live offenders in the tree today**, measured — which is why this is worth
doing now rather than later: the arm ships with no baseline and no exemptions, exactly like the gate
it extends, and that property expires the first time somebody writes one.

Found 29 August, during S11 commit 2 (`d3227c0`). It is the **third** instance of the
vacuous-negation family, after `SuperAdminBypassExclusionTest` and `06e9054`'s
`DiscountPoliciesScreenTest`.

## What happened

The destination-guard arm was first written as:

```php
expect(fn () => /* insert a non-charge line with no destination */)
    ->not->toThrow(QueryException::class, "a {$kind->value} line with no destination was refused");
```

The second argument was meant as a failure description. Pest's signature
(`vendor/pestphp/pest/src/Mixins/Expectation.php:933`):

```php
public function toThrow(callable|string|Throwable $exception, ?string $exceptionMessage = null, string $message = ''): self
```

It landed in **`$exceptionMessage`**. So the arm asserted *"does not throw a QueryException whose
message contains `a waiver line with no destination was refused`"* — trivially true, because the real
message is the trigger's own prose. Under a trigger deliberately mutated to
`NEW.kind IN ('charge','waiver')` the arm reported **8 of 8 green** while the database refused every
waiver line. It was caught by reading the trigger body out of `information_schema` and disbelieving
the green, not by any rule.

This is not the lost-message defect. It is the **vacuous-assertion** one: the arm still runs, still
passes, and no longer tests what it names.

## Why `PestNegatedExpectationMessagesTest` cannot see it

That gate flags a call when the arguments supplied **exceed the index of `$message`**
(`tests/Feature/Quality/PestNegatedExpectationMessagesTest.php`, the `$supplied > $messageIndex`
comparison). For `toThrow`, `$message` is at index **2** and two arguments were supplied.
`2 > 2` is false. Not flagged, and no widening of the existing rule as stated would flag it.

## Why this hole is closeable and the variadic-needle hole is not

The gate's docblock rejects closing the `->not->toContain($needle, $sentence)` hole **on principle**:
a prose sentence is a legal needle, so any rule separating them would be a heuristic tuned against
today's tree. **That reasoning does not extend to this case, and the distinction is structural.**

Census of every matcher Pest declares where something sits between the subject argument and
`$message` — the complete set, four members:

| matcher | line | parameter before `$message` | |
| --- | --- | --- | --- |
| `toThrow` | `:933` | `?string $exceptionMessage = null` | **optional** |
| `toHaveKey` | `:628` | `mixed $value = new Any` | **optional** |
| `toHaveProperty` | `:318` | `mixed $value = new Any` | **optional** |
| `toEqualWithDelta` | `:384` | `float $delta` | **required** |

The first three are **optional**: the matcher is meaningful without them, so supplying one under
`->not->` narrows the negation and can only make it weaker. `toEqualWithDelta`'s `$delta` is
**required** — it is part of the assertion, and `->not->toEqualWithDelta($x, 0.01)` is correct code.

So the discriminator is the vendor's own signature again — `isOptional()`, read by reflection — and
not a judgement about the shape of an argument. It is the same discriminator the existing gate
already trusts, which is the only reason that gate ships with zero exemptions.

`toEqualWithDelta` is the counterexample that matters: a naive rule of *"more than one argument under
`->not->` is a violation"* would flag it, and it is right. The rule must be **optional**, not
**positioned before `$message`**.

## The census, measured 29 August

```
->not->toThrow          16 calls, 0 supplying a second argument
->not->toHaveKey         9 calls, 0 supplying a second argument
->not->toHaveProperty    0 calls
->not->toEqualWithDelta  0 calls
```

The one `toHaveKey` line that greps as having a comma —
`tests/Feature/Casts/MoneyCastIntegrationTest.php:62` — is `json_decode(json_encode($fresh), true)`.
The comma is inside the subject expression. Not an offender.

## What closes it

One change to `pnem_message_parameter_index()`. Instead of the index of `$message`, record the
**threshold**: the lowest index `N` in `[1, M)` whose parameter is optional, or `M` where there is
none. Flag when `$supplied > threshold`. Verified against all four shapes:

| call | threshold | supplied | |
| --- | --- | --- | --- |
| `->not->toBe($x)` | 1 | 1 | not flagged, correct |
| `->not->toThrow(X::class)` | 1 | 1 | not flagged, correct |
| `->not->toThrow(X::class, $s)` | 1 | 2 | **flagged** |
| `->not->toHaveKey('k')` | 1 | 1 | not flagged, correct |
| `->not->toHaveKey('k', $v)` | 1 | 2 | **flagged** |
| `->not->toEqualWithDelta($x, 0.01)` | 2 | 2 | not flagged, correct |
| `->not->toEqualWithDelta($x, 0.01, $m)` | 2 | 3 | **flagged** |
| `->not->toContain('a', 'b')` | — | — | no non-variadic `$message`; untouched |

The parser needs no change; only the index and the failure message do. The message must name the
second defect, because it is not the one the gate currently explains: **the assertion is weaker than
it reads, not merely missing its diagnostic.** Rewrite as `try`/`catch` + `$this->fail()`, which is
what `tests/Feature/Finance/InvoiceLineDestinationRequiredTest.php:261` now does, with the trap
recorded in a comment beside it.

## The part that is not a rule

Three instances now, and **every one was caught by mutating the guard's subject and watching the arm
not fail** — never by reading it. Two of the three survived review by more than one reader. The arm
above is worth having; it does not replace bite-proving, and a green under mutation is the only
evidence that an assertion is load-bearing.
