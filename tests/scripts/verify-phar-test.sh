#!/usr/bin/env bash
# Tests .github/scripts/fetch-phar.sh and verify-phar.sh against a real Deployer release (needs network and gpg).
set -euo pipefail

scripts="$(cd "$(dirname "$0")/../.." && pwd)/.github/scripts"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fails=0
check() { if eval "$2"; then echo "ok: $1"; else echo "FAIL: $1"; fails=1; fi; }

mkdir "$work/good" "$work/missing"
check 'fetches and verifies a real release' '"$scripts/fetch-phar.sh" 8.0.5 "$work/good" > /dev/null'
check 'leaves phar and signature in the directory' '[ -s "$work/good/deployer.phar" ] && [ -s "$work/good/deployer.phar.asc" ]'
check 'fails for a release that does not exist' '! "$scripts/fetch-phar.sh" 0.0.0 "$work/missing" 2> /dev/null'
check 'leaves the directory untouched on failure' '[ -z "$(ls -A "$work/missing")" ]'

cp "$work/good/deployer.phar" "$work/tampered.phar"
printf x >> "$work/tampered.phar"
check 'rejects a tampered phar' '! "$scripts/verify-phar.sh" "$work/tampered.phar" "$work/good/deployer.phar.asc" 2> /dev/null'

GNUPGHOME="$work/foreign" && mkdir -m 700 "$GNUPGHOME" && export GNUPGHOME
gpg --batch --quiet --passphrase '' --quick-gen-key 'Foreign <foreign@example.org>' default default never
gpg --batch --quiet --armor --detach-sign --output "$work/foreign.asc" "$work/good/deployer.phar"
gpgconf --kill gpg-agent
unset GNUPGHOME
check 'rejects a signature by another key' '! "$scripts/verify-phar.sh" "$work/good/deployer.phar" "$work/foreign.asc" 2> /dev/null'

exit $fails
