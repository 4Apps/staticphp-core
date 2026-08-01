#!/usr/bin/env bash
#
# Everything that gates a merge, in one place.
#
# Run it locally, from the pre-commit hook, and from CI, so the three cannot drift.
# Every stage is fatal - the point is to fail the build, not to print advice.
#
#   ./scripts/code_tests.bash
#
# The skeleton's copy of this script also has a js half. There are no assets here, so this
# one is php only and takes no argument; "php" is still accepted so the two can be invoked
# identically from a shared hook.

set -Eeuo pipefail

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_PATH="$(cd "${THIS_DIR}/.." && pwd)"
cd "${APP_DIR:-$BASE_PATH}"

WHAT="${1:-all}"
if [ "$WHAT" != "all" ] && [ "$WHAT" != "php" ]; then
    echo "usage: $0 [all|php]" >&2
    exit 2
fi

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

failed=0

step() {
    printf "${CYAN}==> %s${NC}\n" "$1"
}

ok() {
    printf "${GREEN}    ok${NC}\n"
}

fail() {
    printf "${RED}    FAILED: %s${NC}\n" "$1"
    failed=1
}

if [ ! -x ./vendor/bin/phpunit ]; then
    printf "${RED}vendor/ is missing - run: composer install${NC}\n" >&2
    exit 1
fi

step "php -l (syntax)"
# -print0/-0 so paths with spaces survive
if find src tests -name '*.php' -type f -print0 | xargs -0 -n1 -P4 php -l > /dev/null; then
    ok
else
    fail "php syntax errors"
fi

step "phpcs (code style)"
if ./vendor/bin/phpcs --standard=phpcs.xml src tests; then ok; else fail "phpcs"; fi

step "phpunit"
if ./vendor/bin/phpunit; then ok; else fail "tests"; fi

# The package is only useful if composer can resolve every class in it. A psr-4 dump is
# lenient by default; --strict-psr turns a namespace that does not match its path into an
# error, which is exactly the mistake a file move introduces.
step "composer dump-autoload --strict-psr"
if composer dump-autoload --optimize --strict-psr --no-interaction > /dev/null; then
    ok
else
    fail "psr-4 violations - a class does not match its file path"
fi

step "composer validate"
if composer validate --strict --no-check-publish > /dev/null; then ok; else fail "composer.json"; fi

if [ "$failed" -ne 0 ]; then
    printf "\n${RED}Code tests failed.${NC}\n"
    exit 1
fi

printf "\n${GREEN}All code tests passed.${NC}\n"
