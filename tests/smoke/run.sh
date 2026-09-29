#!/usr/bin/env bash
# Smoke test: the bundled phar runs and every recipe loads with it. No network access needed.
set -euo pipefail
cd "$(dirname "$0")/../.."

expected="Deployer $(php .github/scripts/deployer-update.php bundled-version < composer.json)"
actual="$(php bin/dep --version)"
if [ "$actual" != "$expected" ]; then
    echo "FAIL: expected '$expected', got '$actual'" >&2
    exit 1
fi
echo "ok: $actual"

for recipe in contao symfony typo3; do
    deploy_file="tests/smoke/$recipe/deploy.php"
    # Deployer prints errors to stdout, so show captured output on failure
    if ! out="$(php bin/dep --file "$deploy_file" list 2>&1)"; then
        echo "FAIL: $recipe: dep list failed:" >&2
        echo "$out" >&2
        exit 1
    fi
    if ! tree="$(php bin/dep --file "$deploy_file" tree deploy 2>&1)"; then
        echo "FAIL: $recipe: dep tree deploy failed:" >&2
        echo "$tree" >&2
        exit 1
    fi
    if ! grep -q ask_production_confirmation <<<"$tree"; then
        echo "FAIL: $recipe: our hooks are missing from the deploy task tree" >&2
        exit 1
    fi
    echo "ok: $recipe"
done
