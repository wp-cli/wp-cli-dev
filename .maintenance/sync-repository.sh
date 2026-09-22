#!/usr/bin/env bash

# Bring a single repository up to date: clone it if the folder is missing,
# refresh it otherwise. Freshly cloned repositories are already current, so
# they skip the refresh. This lets the caller run one continuous parallel
# pass over all repositories instead of a clone stage and a refresh stage
# separated by a barrier.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ $# -lt 1 ]]; then
	echo "Usage: sync-repository.sh <destination> [<clone_url>]" >&2
	exit 1
fi

destination="$1"
clone_url="${2:-}"

if [[ ! -d "${destination}" ]]; then
	if [[ -z "${clone_url}" ]]; then
		echo "Folder '${destination}' is missing and no clone URL was provided." >&2
		exit 1
	fi
	exec bash "${SCRIPT_DIR}/clone-repository.sh" "${destination}" "${clone_url}"
fi

exec php "${SCRIPT_DIR}/refresh-repository.php" "${destination}"
