#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v jq &>/dev/null; then
	echo "Required command 'jq' is not installed or not available in PATH." >&2
	exit 1
fi

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
	DETECTED_CORES=$(nproc)
elif command -v sysctl &>/dev/null; then
	DETECTED_CORES=$(sysctl -n hw.logicalcpu 2>/dev/null || echo 4)
else
	DETECTED_CORES=4
fi

MAX_CORES=8
CORES="${CLONE_JOBS:-${WPCLI_DEV_JOBS:-${DETECTED_CORES}}}"

if ! [[ "${CORES}" =~ ^[1-9][0-9]*$ ]]; then
	CORES=4
elif [[ -z "${CLONE_JOBS:-}" && -z "${WPCLI_DEV_JOBS:-}" && "${CORES}" -gt "${MAX_CORES}" ]]; then
	CORES=${MAX_CORES}
fi

# Fetch repository list from the GitHub API.
CURL_OPTS=(-fsS)
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
	CURL_OPTS+=(--header "Authorization: Bearer ${GITHUB_TOKEN}")
fi

if ! RESPONSE=$(curl "${CURL_OPTS[@]}" 'https://api.github.com/orgs/wp-cli/repos?per_page=100'); then
	echo "Failed to fetch repository list from the GitHub API." >&2
	exit 1
fi

# Validate the response shape and detect API errors such as rate limiting.
if ! jq -e 'type == "array"' >/dev/null <<< "${RESPONSE}"; then
	if jq -e '.message' >/dev/null <<< "${RESPONSE}"; then
		MESSAGE=$(jq -r '.message' <<< "${RESPONSE}")
		echo "GitHub responded with: ${MESSAGE}" >&2
		echo "If you are running into a rate limiting issue during large events please set GITHUB_TOKEN environment variable." >&2
		echo "See https://github.com/settings/tokens" >&2
	else
		echo "GitHub API returned an unexpected response; expected a JSON array of repositories." >&2
	fi
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
	printf '%s\n' "${UPDATE_FOLDERS[@]}" | xargs -P"${CORES}" -I% php "${SCRIPT_DIR}/refresh-repository.php" %
fi
