#!/usr/bin/env bash
# Tests .github/scripts/open-pr.sh against a local bare remote and a stub `gh` that logs its arguments.
# The base branch is deliberately not called "main".
set -euo pipefail

script="$(cd "$(dirname "$0")/../.." && pwd)/.github/scripts/open-pr.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fails=0
check() { if eval "$2"; then echo "ok: $1"; else echo "FAIL: $1"; fails=1; fi; }

# Stub gh: "pr list" prints $work/open-pr (empty = no open PR), "pr view" prints the comments that
# "pr comment" appended to $work/comments; every call is logged.
mkdir "$work/stub"
cat > "$work/stub/gh" <<STUB
#!/usr/bin/env bash
echo "\$*" >> "$work/gh.log"
if [ "\$1 \$2" = "pr list" ]; then cat "$work/open-pr" 2>/dev/null || true; fi
if [ "\$1 \$2" = "pr view" ]; then cat "$work/comments" 2>/dev/null || true; fi
if [ "\$1 \$2" = "pr comment" ]; then echo "\$*" >> "$work/comments"; fi
STUB
chmod +x "$work/stub/gh"
export PATH="$work/stub:$PATH"
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.org GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.org

git init --quiet --bare "$work/remote.git"
git init --quiet -b v2 "$work/repo"
cd "$work/repo"
git remote add origin "$work/remote.git"
echo '{"extra": {"deployer": {"version": "8.0.5"}}}' > composer.json
git add composer.json && git commit --quiet -m init && git push --quiet origin v2
echo body > "$work/body.md"
bump() { echo "{\"extra\": {\"deployer\": {\"version\": \"$1\"}}}" > composer.json; }
remote_version() { git --git-dir="$work/remote.git" show "deployer/9:composer.json" | grep -o '"version": "[^"]*"'; }

# 1. No branch, no PR: pushes the branch and creates a draft PR.
bump 9.0.0
"$script" deployer/9 9.0.0 true "$work/body.md" v2 > /dev/null
check 'first run pushes the branch' '[ "$(remote_version)" = "\"version\": \"9.0.0\"" ]'
check 'first run creates a draft PR' 'grep -q -- "pr create --base v2 --head deployer/9 --title Bundle Deployer 9.0.0 --body-file $work/body.md --draft" "$work/gh.log"'

# 2. Same version again: no push, no gh calls.
git switch --quiet v2 && bump 9.0.0 && : > "$work/gh.log"
before="$(git --git-dir="$work/remote.git" rev-parse deployer/9)"
"$script" deployer/9 9.0.0 true "$work/body.md" v2 > /dev/null
check 'unchanged version does not push' '[ "$(git --git-dir="$work/remote.git" rev-parse deployer/9)" = "$before" ]'
check 'unchanged version does not call gh' '[ ! -s "$work/gh.log" ]'

# 3. Newer version with the PR still open: force-pushes and edits the PR instead of creating another.
#    Someone else's commit on the base branch (not on the PR branch) must not count as a foreign commit on the branch.
git clone --quiet --branch v2 "$work/remote.git" "$work/main-human"
(cd "$work/main-human" && echo docs > README.md && git add README.md \
    && GIT_AUTHOR_EMAIL=human@example.org git commit --quiet -m "Docs" && git push --quiet origin v2)
git checkout --quiet -f v2 && git pull --quiet --ff-only origin v2 && bump 9.0.1 && echo 42 > "$work/open-pr"
"$script" deployer/9 9.0.1 true "$work/body.md" v2 > /dev/null
check 'newer version force-pushes the branch' '[ "$(remote_version)" = "\"version\": \"9.0.1\"" ]'
check 'newer version edits the open PR' 'grep -q -- "pr edit 42 --title Bundle Deployer 9.0.1" "$work/gh.log"'
check 'newer version creates no second PR' '[ "$(grep -c "pr create" "$work/gh.log")" = 0 ]'

# 3b. The branch now contains someone else's commit from the base branch; that must not block the next update.
git checkout --quiet -f v2 && bump 9.0.2 && : > "$work/gh.log"
"$script" deployer/9 9.0.2 true "$work/body.md" v2 > /dev/null
check 'commits from the base branch do not block updates' '[ "$(remote_version)" = "\"version\": \"9.0.2\"" ]'

# 5. Someone pushed a commit to the branch: a newer version must not force-push over it, but comment once.
git clone --quiet --branch deployer/9 "$work/remote.git" "$work/human"
(cd "$work/human" && echo fix > recipe-fix.php && git add recipe-fix.php \
    && GIT_AUTHOR_EMAIL=human@example.org git commit --quiet -m "Fix recipe for Deployer 9" && git push --quiet origin deployer/9)
human_head="$(git --git-dir="$work/remote.git" rev-parse deployer/9)"
git checkout --quiet -f v2 && bump 9.0.3 && : > "$work/gh.log"
"$script" deployer/9 9.0.3 true "$work/body.md" v2 > /dev/null 2>&1
check 'branch with foreign commits is not force-pushed' '[ "$(git --git-dir="$work/remote.git" rev-parse deployer/9)" = "$human_head" ]'
check 'branch with foreign commits gets a comment instead' 'grep -q -- "pr comment 42 --body Deployer 9.0.3" "$work/gh.log"'

# 6. Same situation on the next run: no second comment.
git checkout --quiet -f v2 && bump 9.0.3 && : > "$work/gh.log"
"$script" deployer/9 9.0.3 true "$work/body.md" v2 > /dev/null 2>&1
check 'foreign-commit comment is posted only once' '[ "$(grep -c "pr comment" "$work/gh.log")" = 0 ]'

# 4. Non-draft mode omits --draft.
git checkout --quiet -f v2 && bump 8.0.6 && rm -f "$work/open-pr" && : > "$work/gh.log"
"$script" deployer/8 8.0.6 false "$work/body.md" v2 > /dev/null
check 'non-draft PR has no --draft' 'grep -q "pr create" "$work/gh.log" && ! grep -q -- "--draft" "$work/gh.log"'

exit $fails
