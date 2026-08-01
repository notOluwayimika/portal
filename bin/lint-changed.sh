#!/usr/bin/env bash
#
# Changed-files lint gate.
#
# The codebase has a large backlog of pre-existing style/lint drift. Rather than
# reformat everything at once, this gate runs Pint / Prettier / ESLint only on
# the files changed since a base commit — so new and modified code must be clean
# while legacy drift is grandfathered and burns down as files are touched.
#
# Usage: bin/lint-changed.sh <base-sha-or-ref>
# (Kept portable: no `mapfile`, so it runs on macOS bash 3.2 and CI bash 5.)
set -o pipefail

BASE="${1:-}"
if [ -z "$BASE" ] || ! git rev-parse --verify --quiet "$BASE^{commit}" >/dev/null; then
  echo "lint-changed: base '$BASE' not found; falling back to origin/main"
  BASE="origin/main"
fi

php_files=()
prettier_files=()
eslint_files=()
status_unresolved=0
# -z / read -d '': `git diff --name-only` QUOTES and octal-escapes any path holding a
# non-ASCII byte, emitting `"resources/js/a \342\200\224 b.ts"` rather than the real
# name. `[ -f ]` then fails on that quoted string and the file drops out of the lists
# below — silently, with the gate still exiting 0. `-z` emits raw NUL-terminated
# paths, so nothing needs unquoting. (`read -r -d ''` works on macOS bash 3.2; still
# no `mapfile`.)
while IFS= read -r -d '' f; do
  # A trailing empty read is normal; skip it quietly.
  [ -n "$f" ] || continue
  # A path that git reports as changed but that is not a file on disk is either a bug
  # in this script or something nobody anticipated. It must NOT be a silent `continue`:
  # in a lint gate that produces a green meaning "I did not look", which is worse than
  # a red. Name it and fail.
  if [ ! -f "$f" ]; then
    echo "lint-changed: NOT LINTED — git reported '$f' as changed but it is not a file on disk"
    status_unresolved=1
    continue
  fi
  case "$f" in
    *.php) php_files+=("$f") ;;
  esac
  case "$f" in
    resources/*.ts|resources/*.tsx|resources/*.js|resources/*.jsx|resources/*.vue|resources/*.css|resources/*.json) prettier_files+=("$f") ;;
  esac
  case "$f" in
    *.ts|*.tsx|*.js|*.jsx) eslint_files+=("$f") ;;
  esac
done < <(git diff -z --name-only --diff-filter=ACMR "$BASE"...HEAD)

status="$status_unresolved"

if [ "${#php_files[@]}" -gt 0 ]; then
  echo "==> Pint (check) on ${#php_files[@]} changed PHP file(s)"
  ./vendor/bin/pint --test "${php_files[@]}" || status=1
else
  echo "==> Pint: no changed PHP files"
fi

if [ "${#prettier_files[@]}" -gt 0 ]; then
  echo "==> Prettier (check) on ${#prettier_files[@]} changed file(s)"
  pnpm exec prettier --check "${prettier_files[@]}" || status=1
else
  echo "==> Prettier: no changed frontend files"
fi

if [ "${#eslint_files[@]}" -gt 0 ]; then
  echo "==> ESLint on ${#eslint_files[@]} changed file(s)"
  pnpm exec eslint "${eslint_files[@]}" || status=1
else
  echo "==> ESLint: no changed JS/TS files"
fi

if [ "$status" -ne 0 ]; then
  echo ""
  echo "lint-changed: style/lint issues in changed files. Run 'composer lint', 'pnpm run format', 'pnpm run lint' to fix."
fi
exit "$status"
