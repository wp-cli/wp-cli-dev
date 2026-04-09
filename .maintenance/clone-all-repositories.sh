#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

SKIP_LIST=(
	"autoload-splitter"
	"composer-changelogs"
	"dash-docset-generator"
	"ideas"
	"package-index"
	"regenerate-readme"
	"sample-plugin"
	"wp-cli-dev"
	"wp-cli-roadmap"
)

# Detect number of CPU cores, defaulting to 4.
if command -v nproc &>/dev/null; then
	CORES=$(nproc)
elif command -v sysctl &>/dev/null; then
	CORES=$(sysctl -n hw.logicalcpu 2>/dev/null || echo 4)
else
	CORES=4
fi

# Fetch repository list from the GitHub API.
CURL_OPTS=(-s)
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
	CURL_OPTS+=(--header "Authorization: Bearer ${GITHUB_TOKEN}")
fi

RESPONSE=$(curl "${CURL_OPTS[@]}" 'https://api.github.com/orgs/wp-cli/repos?per_page=100')

# Detect API errors such as rate limiting.
if echo "${RESPONSE}" | jq -e '.message' &>/dev/null; then
	MESSAGE=$(echo "${RESPONSE}" | jq -r '.message')
	echo "GitHub responded with: ${MESSAGE}"
	echo "If you are running into a rate limiting issue during large events please set GITHUB_TOKEN environment variable."
	echo "See https://github.com/settings/tokens"
	exit 1
fi

is_skipped() {
	local name="$1"
	for skip in "${SKIP_LIST[@]}"; do
		[[ "${skip}" == "${name}" ]] && return 0
	done
	return 1
}

get_destination() {
	local name="$1"
	if [[ "${name}" == ".github" ]]; then
		echo "dot-github"
	else
		echo "${name}"
	fi
}

CLONE_LIST=()
UPDATE_FOLDERS=()

while IFS=$'\t' read -r name clone_url ssh_url; do
	if is_skipped "${name}"; then
		continue
	fi

	destination=$(get_destination "${name}")

	if [[ ! -d "${destination}" ]]; then
		if [[ -n "${GITHUB_ACTION:-}" ]]; then
			CLONE_LIST+=("${destination}"$'\t'"${clone_url}")
		else
			CLONE_LIST+=("${destination}"$'\t'"${ssh_url}")
		fi
	fi

	UPDATE_FOLDERS+=("${destination}")
done < <(echo "${RESPONSE}" | jq -r '.[] | [.name, .clone_url, .ssh_url] | @tsv')

if [[ ${#CLONE_LIST[@]} -gt 0 ]]; then
	printf '%s\n' "${CLONE_LIST[@]}" | xargs -n2 -P"${CORES}" bash "${SCRIPT_DIR}/clone-repository.sh"
fi

if [[ ${#UPDATE_FOLDERS[@]} -gt 0 ]]; then
	printf '%s\n' "${UPDATE_FOLDERS[@]}" | xargs -n1 -P"${CORES}" -I% php "${SCRIPT_DIR}/refresh-repository.php" %
fi
