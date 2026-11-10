#!/usr/bin/env bash
# Pushes the module's split (SHA) as BRANCH, and as the tag TAG when set, to a repository over SSH with the
# deploy key KEY: `push-split.sh <repository> <host>`. Without a key, says so and pushes nothing.
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
ssh-keyscan -t ed25519,rsa "$host" > "$directory/known_hosts" 2>/dev/null
export GIT_SSH_COMMAND="ssh -i $directory/key -o UserKnownHostsFile=$directory/known_hosts -o IdentitiesOnly=yes"

git push "$repository" "$SHA:refs/heads/$BRANCH"
if [ -n "${TAG:-}" ]; then
  git -c user.name="github-actions[bot]" -c user.email="41898282+github-actions[bot]@users.noreply.github.com" \
    tag -a -f "$TAG" "$SHA" -m "janalis/collaborate_drupal $TAG"
  git push "$repository" "refs/tags/$TAG"
fi
