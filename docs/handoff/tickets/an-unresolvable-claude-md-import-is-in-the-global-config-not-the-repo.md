# The unresolvable `CLAUDE.md` import is in the GLOBAL config, not this repo — and that is the finding

**Status:** open, not implemented. Raised by the cold review of `feat/server-side-money-formatter`,
2026-08-23.

## The finding as it arrived, and what the tree actually says

The review reported: *"The repo's CLAUDE.md imports a file that exists only on one machine, so a
fresh clone gets a CLAUDE.md whose imports do not resolve."*

**Measured, that is not true of this repository.** `CLAUDE.md` at the repository root contains **zero
`@` imports** — `grep -c '@' CLAUDE.md` returns `0`. A fresh clone gets a self-contained file, and
nothing in it fails to resolve.

The unresolvable import is real, but it lives one level up: the developer's **global**
`~/.claude/CLAUDE.md` ends with `@RTK.md`, and `RTK.md` sits beside it in that machine-local
directory. It is never cloned, because it is not in any repository.

## Why the corrected version is still worth a ticket

Not as a repo defect — as a **reporting hazard**, and this ticket is the record of it.

The global file is loaded into every agent session on that machine, alongside whatever the repository
provides, and the two are presented together. So an import that only ever resolves on one machine is
indistinguishable, from inside a session, from one the repository shipped. This review read a
machine-local instruction as a repository instruction, and the same confusion runs the other way:
**a convention that lives only in the global file will look, to an agent working here, exactly like a
convention this project agreed to** — and it will be followed, cited, and eventually written into a
document that outlives the machine.

That is the same failure this branch spent two commits on in a different costume: a rule that appears
enforced because the thing displaying it does not distinguish where it came from.

## What a fix would have to decide

Whether anything currently in `~/.claude/CLAUDE.md` is in fact a *project* convention wearing a
global's clothes — and if so, move it here where a clone can see it. That is a judgement about
content nobody has made yet, not an edit. Conversely, if everything there is genuinely personal
(tooling preferences, a token-saving proxy), nothing needs to move and this ticket closes as
"checked, correctly separated".

## Not asserted

The contents of `~/.claude/CLAUDE.md` beyond the import line itself, and whether `RTK.md` is
duplicated anywhere in this repository under another name.
