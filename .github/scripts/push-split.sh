#!/usr/bin/env bash
# Pushes the module's split (SHA) as BRANCH, and as the tag TAG when set, to a repository over SSH with the
# deploy key KEY: `push-split.sh <repository> <host>`, the host's SSH key pinned in known_hosts. Without a deploy
# key, says so and pushes nothing.
set -euo pipefail

repository=$1
host=$2
if [ -z "${KEY:-}" ]; then
  echo "::notice::No deploy key for $repository: the split is not pushed there."
  exit 0
fi

directory=$(mktemp -d)
trap 'rm -rf "$directory"' EXIT
printf '%s\n' "$KEY" > "$directory/key"
chmod 600 "$directory/key"
# The hosts' keys are pinned (known_hosts, next to this script): a scan at run time can fail, or be answered by
# another host.
known_hosts="$(cd "$(dirname "$0")" && pwd)/known_hosts"
grep -q "^$host " "$known_hosts" || { echo "::error::No pinned SSH host key for $host"; exit 1; }
export GIT_SSH_COMMAND="ssh -i $directory/key -o UserKnownHostsFile=$known_hosts -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes"

git push "$repository" "$SHA:refs/heads/$BRANCH"
if [ -n "${TAG:-}" ]; then
  git -c user.name="github-actions[bot]" -c user.email="41898282+github-actions[bot]@users.noreply.github.com" \
    tag -a -f "$TAG" "$SHA" -m "janalis/collaborate_drupal $TAG"
  git push "$repository" "refs/tags/$TAG"
fi
