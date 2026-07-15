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
while IFS= read -r f; do
  [ -n "$f" ] && [ -f "$f" ] || continue
  case "$f" in
    *.php) php_files+=("$f") ;;
  esac
  case "$f" in
    resources/*.ts|resources/*.tsx|resources/*.js|resources/*.jsx|resources/*.vue|resources/*.css|resources/*.json) prettier_files+=("$f") ;;
  esac
  case "$f" in
    *.ts|*.tsx|*.js|*.jsx) eslint_files+=("$f") ;;
  esac
done < <(git diff --name-only --diff-filter=ACMR "$BASE"...HEAD)

status=0

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
