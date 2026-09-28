#!/usr/bin/env bash
# Usage: fetch-phar.sh <deployer-version> <directory>
# Downloads deployer.phar and deployer.phar.asc of a Deployer release, verifies the signature and only then
# moves both files into <directory>. On any failure <directory> is left untouched.
set -euo pipefail

version=$1 directory=$2
base="https://github.com/deployphp/deployer/releases/download/v$version"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

for file in deployer.phar deployer.phar.asc; do
    curl --fail --silent --show-error --location --retry 3 --output "$work/$file" "$base/$file"
done

"$(dirname "$0")/verify-phar.sh" "$work/deployer.phar" "$work/deployer.phar.asc"

mv "$work/deployer.phar" "$work/deployer.phar.asc" "$directory/"
