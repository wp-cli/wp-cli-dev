#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export SCRIPT_DIR

# peek.php renders a live lane of output per parallel job. Its use is
# optional: it degrades to a plain passthrough on platforms that cannot
# support the display (e.g. Windows) and can be disabled with NO_PEEK=1.
PEEK_PHP="${SCRIPT_DIR}/peek.php"
export PEEK_PHP

# Parallel jobs run behind the peek display, where an interactive prompt is
# instantly overdrawn and would hang the run waiting for input nobody can
# see. Git and ssh prompt on /dev/tty (not stdin), so disable prompting
# entirely: failures then surface as visible errors in the job's lane.
# - GIT_TERMINAL_PROMPT=0: no credential/username prompts from git itself.
# - BatchMode=yes: ssh fails instead of asking for passphrases or host key
#   confirmation (keys served by an ssh-agent keep working). Only set when
#   GIT_SSH_COMMAND is not already customized.
# - GIT_MERGE_AUTOEDIT=no: a non-fast-forward pull keeps the default merge
#   message instead of opening an editor.
export GIT_TERMINAL_PROMPT=0
export GIT_MERGE_AUTOEDIT=no
if [[ -z "${GIT_SSH_COMMAND:-}" ]]; then
	export GIT_SSH_COMMAND="ssh -oBatchMode=yes"
fi

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

# One task per repository: sync-repository.sh clones missing folders and
# refreshes existing ones. Running a single parallel pass over all
# repositories keeps all ${CORES} slots busy for the whole run, instead of
# a clone stage and a refresh stage separated by a barrier.
TASK_LIST=()

while IFS=$'\t' read -r name clone_url ssh_url; do
	if is_skipped "${name}"; then
		continue
	fi

	destination=$(get_destination "${name}")

	if [[ -n "${GITHUB_ACTION:-}" ]]; then
		TASK_LIST+=("${destination}"$'\t'"${clone_url}")
	else
		TASK_LIST+=("${destination}"$'\t'"${ssh_url}")
	fi
done < <(echo "${RESPONSE}" | jq -r '.[] | [.name, .clone_url, .ssh_url] | @tsv')

if [[ ${#TASK_LIST[@]} -gt 0 ]]; then
	printf '%s\n' "${TASK_LIST[@]}" | php "${PEEK_PHP}" -- xargs -n2 -P"${CORES}" \
		bash -c 'exec php "${PEEK_PHP}" run -n "$1" -- bash "${SCRIPT_DIR}/sync-repository.sh" "$1" "$2"' _
fi
