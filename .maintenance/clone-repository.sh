#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 2 ]]; then
	echo "Usage: clone-repository.sh <destination> <clone_url>" >&2
	exit 1
fi

destination="$1"
clone_url="$2"

printf "Fetching \033[32m%s\033[0m...\n" "${destination}"
git clone "${clone_url}" "${destination}"
