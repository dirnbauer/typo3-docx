#!/usr/bin/env bash
#
# runTests.sh — unified entry point for local and CI test runs.
#
# Usage:
#   Build/Scripts/runTests.sh -s <suite> [-p <php>]
#
#   Suites: unit | phpstan | composer | assets | ci

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
  unit       PHPUnit unit tests.
  phpstan    Static analysis.
  composer   composer validate + composer audit.
  assets     npm ci + production frontend build.
  ci         composer + phpstan + unit + assets (no DB functional tests).

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

run_unit() {
    php -d memory_limit="${PHP_MEMORY_LIMIT}" vendor/bin/phpunit -c Build/phpunit/UnitTests.xml
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
    unit) run_unit ;;
    phpstan) run_phpstan ;;
    composer) run_composer ;;
    assets) run_assets ;;
    ci)
        run_composer
        run_phpstan
        run_unit
        run_assets
        ;;
    *)
        echo "Unknown suite: ${SUITE}" >&2
        usage
        exit 64
        ;;
esac
