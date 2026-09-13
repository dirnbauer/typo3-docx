#!/usr/bin/env bash
#
# runTests.sh — unified entry point for local and CI test runs.
#
# Usage:
#   Build/Scripts/runTests.sh -s <suite> [-p <php>]
#
#   Suites: lint | cgl | phpstan | unit | functional | composer | assets | ci

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${PROJECT_ROOT}"

SUITE=""
PHP_VERSION=""
PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-1G}"

usage() {
    cat <<'EOF'
Usage: Build/Scripts/runTests.sh -s <suite> [-p <php>]

Suites:
  lint       php -l over all PHP sources.
  cgl        Coding standards (php-cs-fixer, dry-run).
  phpstan    Static analysis (level 8).
  unit       PHPUnit unit tests.
  functional PHPUnit functional tests (sqlite by default).
  composer   composer validate + composer audit.
  assets     npm ci + patch check + production frontend build.
  ci         composer + lint + cgl + phpstan + unit + functional + assets.

Options:
  -p <php>   Informational PHP version label.
  -h         Show this help.
EOF
}

while getopts "s:p:h" opt; do
    case "${opt}" in
        s) SUITE="${OPTARG}" ;;
        p) PHP_VERSION="${OPTARG}" ;;
        h) usage; exit 0 ;;
        *) usage; exit 64 ;;
    esac
done

if [[ -z "${SUITE}" ]]; then
    usage
    exit 64
fi

if [[ -n "${PHP_VERSION}" ]]; then
    echo "# Target PHP version: ${PHP_VERSION} (informational)"
fi

run_lint() {
    find Classes Configuration Tests ext_localconf.php -name '*.php' -print0 \
        | xargs -0 -n1 -P4 php -l > /dev/null
}

run_cgl() {
    vendor/bin/php-cs-fixer check --diff
}

run_unit() {
    php -d memory_limit="${PHP_MEMORY_LIMIT}" vendor/bin/phpunit -c Build/phpunit/UnitTests.xml
}

run_functional() {
    php -d memory_limit="${PHP_MEMORY_LIMIT}" vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml
}

run_phpstan() {
    vendor/bin/phpstan analyse --no-progress --memory-limit=512M
}

run_composer() {
    composer validate --strict
    if [[ -f composer.lock ]]; then
        composer audit --locked --abandoned=report
    fi
}

run_assets() {
    if [[ ! -f package.json ]]; then
        echo "package.json missing" >&2
        exit 1
    fi
    npm ci
    npm run test:build
    npm run build
    if [[ ! -f Resources/Public/Vite/manifest.json ]]; then
        echo "Vite manifest was not created." >&2
        exit 1
    fi
}

case "${SUITE}" in
    lint) run_lint ;;
    cgl) run_cgl ;;
    unit) run_unit ;;
    functional) run_functional ;;
    phpstan) run_phpstan ;;
    composer) run_composer ;;
    assets) run_assets ;;
    ci)
        run_composer
        run_lint
        run_cgl
        run_phpstan
        run_unit
        run_functional
        run_assets
        ;;
    *)
        echo "Unknown suite: ${SUITE}" >&2
        usage
        exit 64
        ;;
esac
