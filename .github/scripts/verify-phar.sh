#!/usr/bin/env bash
# Usage: verify-phar.sh <phar> <signature>
# Exits 0 only if <signature> is a valid signature of <phar> by the Deployer sign key.
set -euo pipefail

fingerprint=0C331EAD47B77AC7DCA31D99EFC847736630CF36
key="$(cd "$(dirname "$0")/.." && pwd)/deployer-sign-key.asc"

GNUPGHOME="$(mktemp -d)"
export GNUPGHOME
trap 'gpgconf --kill gpg-agent 2>/dev/null || true; rm -rf "$GNUPGHOME"' EXIT

gpg --batch --quiet --import "$key" 2>/dev/null

if ! status="$(gpg --batch --status-fd 1 --verify "$2" "$1" 2>/dev/null)"; then
    echo "Signature check failed: $2 is not a valid signature of $1" >&2
    exit 1
fi

# VALIDSIG's last field is the primary key fingerprint.
if ! grep -Eq "^\[GNUPG:\] VALIDSIG .* ${fingerprint}\$" <<<"$status"; then
    echo "Signature check failed: $1 is not signed by ${fingerprint}" >&2
    exit 1
fi

echo "ok: $1 is signed by the Deployer sign key"
