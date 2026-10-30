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

# Lists the repositories of the organization as "name<TAB>clone_url<TAB>ssh_url"
# lines. Uses the GitHub CLI when it is logged in, and the GitHub API with
# GH_TOKEN or GITHUB_TOKEN, or anonymously, otherwise.
fetch_repositories() {
	if command -v gh &>/dev/null && gh auth status --hostname github.com &>/dev/null; then
		gh api --paginate 'orgs/wp-cli/repos?per_page=100' --jq '.[] | [.name, .clone_url, .ssh_url] | @tsv'
		return
	fi

	local curl_opts=(-sS)
	local token="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
	if [[ -n "${token}" ]]; then
		curl_opts+=(--header "Authorization: Bearer ${token}")
	fi

	local page=1 response message
	while true; do
		if ! response=$(curl "${curl_opts[@]}" "https://api.github.com/orgs/wp-cli/repos?per_page=100&page=${page}"); then
			echo "Failed to fetch repository list from the GitHub API." >&2
			return 1
		fi

		# Validate the response shape and detect API errors such as rate limiting.
		if ! jq -e 'type == "array"' >/dev/null <<< "${response}"; then
			if jq -e '.message' >/dev/null 2>&1 <<< "${response}"; then
				message=$(jq -r '.message' <<< "${response}")
				echo "GitHub responded with: ${message}" >&2
				echo "If you are running into a rate limiting issue, log in with 'gh auth login' or set the GITHUB_TOKEN environment variable." >&2
			else
				echo "GitHub API returned an unexpected response; expected a JSON array of repositories." >&2
			fi
			return 1
		fi

		if [[ $(jq 'length' <<< "${response}") -eq 0 ]]; then
			return
		fi

		if ! jq -r '.[] | [.name, .clone_url, .ssh_url] | @tsv' <<< "${response}"; then
			echo "GitHub API returned repositories in an unexpected format." >&2
			return 1
		fi
		page=$((page + 1))
	done
}

# Clone over the protocol the GitHub CLI is set up for, as its credential helper
# then covers pushing. Without it, use HTTPS on CI and SSH everywhere else.
if [[ -n "${GITHUB_ACTION:-}" ]]; then
	PROTOCOL="https"
elif command -v gh &>/dev/null && gh auth status --hostname github.com &>/dev/null; then
	PROTOCOL=$(gh config get git_protocol --host github.com 2>/dev/null || echo "https")
else
	PROTOCOL="ssh"
fi

REPOSITORIES=$(fetch_repositories)

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
	if [[ -z "${name}" ]]; then
		continue
	fi

	if is_skipped "${name}"; then
		continue
	fi

	destination=$(get_destination "${name}")

	if [[ ! -d "${destination}" ]]; then
		if [[ "${PROTOCOL}" == "ssh" ]]; then
			CLONE_LIST+=("${destination}"$'\t'"${ssh_url}")
		else
			CLONE_LIST+=("${destination}"$'\t'"${clone_url}")
		fi
	fi

	UPDATE_FOLDERS+=("${destination}")
done <<< "${REPOSITORIES}"

if [[ ${#CLONE_LIST[@]} -gt 0 ]]; then
	printf '%s\n' "${CLONE_LIST[@]}" | xargs -n2 -P"${CORES}" bash "${SCRIPT_DIR}/clone-repository.sh"
fi

if [[ ${#UPDATE_FOLDERS[@]} -gt 0 ]]; then
	printf '%s\n' "${UPDATE_FOLDERS[@]}" | xargs -P"${CORES}" -I% php "${SCRIPT_DIR}/refresh-repository.php" %
fi
