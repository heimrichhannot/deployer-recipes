#!/usr/bin/env bash
# Tests .github/scripts/open-pr.sh against a local bare remote and a stub `gh` that logs its arguments.
set -euo pipefail

script="$(cd "$(dirname "$0")/../.." && pwd)/.github/scripts/open-pr.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fails=0
check() { if eval "$2"; then echo "ok: $1"; else echo "FAIL: $1"; fails=1; fi; }

# Stub gh: "pr list" prints $work/open-pr (empty = no open PR); every call is logged.
mkdir "$work/stub"
cat > "$work/stub/gh" <<STUB
#!/usr/bin/env bash
echo "\$*" >> "$work/gh.log"
if [ "\$1 \$2" = "pr list" ]; then cat "$work/open-pr" 2>/dev/null || true; fi
STUB
chmod +x "$work/stub/gh"
export PATH="$work/stub:$PATH"
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.org GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.org

git init --quiet --bare "$work/remote.git"
git init --quiet -b main "$work/repo"
cd "$work/repo"
git remote add origin "$work/remote.git"
echo '{"extra": {"deployer": {"version": "8.0.5"}}}' > composer.json
git add composer.json && git commit --quiet -m init && git push --quiet origin main
echo body > "$work/body.md"
bump() { echo "{\"extra\": {\"deployer\": {\"version\": \"$1\"}}}" > composer.json; }
remote_version() { git --git-dir="$work/remote.git" show "deployer/9:composer.json" | grep -o '"version": "[^"]*"'; }

# 1. No branch, no PR: pushes the branch and creates a draft PR.
bump 9.0.0
"$script" deployer/9 9.0.0 true "$work/body.md" > /dev/null
check 'first run pushes the branch' '[ "$(remote_version)" = "\"version\": \"9.0.0\"" ]'
check 'first run creates a draft PR' 'grep -q -- "pr create --base main --head deployer/9 --title Bundle Deployer 9.0.0 --body-file $work/body.md --draft" "$work/gh.log"'

# 2. Same version again: no push, no gh calls.
git switch --quiet main && bump 9.0.0 && : > "$work/gh.log"
before="$(git --git-dir="$work/remote.git" rev-parse deployer/9)"
"$script" deployer/9 9.0.0 true "$work/body.md" > /dev/null
check 'unchanged version does not push' '[ "$(git --git-dir="$work/remote.git" rev-parse deployer/9)" = "$before" ]'
check 'unchanged version does not call gh' '[ ! -s "$work/gh.log" ]'

# 3. Newer version with the PR still open: force-pushes and edits the PR instead of creating another.
git checkout --quiet -f main && bump 9.0.1 && echo 42 > "$work/open-pr"
"$script" deployer/9 9.0.1 true "$work/body.md" > /dev/null
check 'newer version force-pushes the branch' '[ "$(remote_version)" = "\"version\": \"9.0.1\"" ]'
check 'newer version edits the open PR' 'grep -q -- "pr edit 42 --title Bundle Deployer 9.0.1" "$work/gh.log"'
check 'newer version creates no second PR' '[ "$(grep -c "pr create" "$work/gh.log")" = 0 ]'

# 4. Non-draft mode omits --draft.
git checkout --quiet -f main && bump 8.0.6 && rm -f "$work/open-pr" && : > "$work/gh.log"
"$script" deployer/8 8.0.6 false "$work/body.md" > /dev/null
check 'non-draft PR has no --draft' 'grep -q "pr create" "$work/gh.log" && ! grep -q -- "--draft" "$work/gh.log"'

exit $fails
