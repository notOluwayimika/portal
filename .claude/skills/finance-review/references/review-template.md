# Review — template

No summary of the change. The reader has the diff. Findings, then coverage.

---

## Verdict

<Ship / ship with fixes / do not ship.> <One sentence.>

## Findings

### 1. <One-line statement of what is wrong> — **stop | fix | ticket**

**Evidence.** `path/to/file.php:LINE` — <the line, or pasted output>.

**Failure.** <The concrete case where this goes wrong: what input or state, what
happens, in which environment. Not an abstraction.>

**Severity.** <Why this level and not the next one up.>

**Closes it.** <The mechanism. A doc note is not a mechanism.>

### 2. <…>

<Repeat. Most severe first.>

## Checked, no finding

<What you attacked and what held. Two or three lines. This is what your green
covers — without it, a clean review is indistinguishable from no review.>

- Premise: <confirmed against `path:LINE`.>
- Guard scope: <what violation you tried to construct that would slip past, and
  why it cannot.>
- Ordering / rollback: <what you traced.>
- Environment: <fresh vs existing vs production — which you reasoned through.>
- Numbers: <which you re-derived.>

## Not checked

<Anything out of scope for this depth, said explicitly, so nobody reads the
verdict as broader than it is.>
