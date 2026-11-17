#!/usr/bin/env bash
#
# Updates the bundled packages in the composer.lock of wp-cli/wp-cli-bundle to
# their latest releases, and opens a pull request with the change, or updates
# the one that is open already:
#
#   .maintenance/refresh-bundle-lock.sh [--dry-run]
#
# Only the wp-cli/* packages move, within the constraints of composer.json, and
# only composer.lock changes. The wp-cli/wp-cli framework is left alone, as the
# "Update wp-cli framework" workflow of the bundle takes care of it. So are
# third-party dependencies, which Dependabot updates after a cooldown.
#
# With --dry-run, prints the changes without pushing anything. Prints the URL of
# the pull request otherwise, or nothing when the lockfile is up to date.
#

set -euo pipefail

REPO="wp-cli/wp-cli-bundle"
BRANCH="update-bundled-packages"
TITLE="Update bundled packages to their latest releases"

DRY_RUN=false
case "${1:-}" in
	"") ;;
	--dry-run) DRY_RUN=true ;;
	*)
		echo "Usage: refresh-bundle-lock.sh [--dry-run]" >&2
		exit 1
		;;
esac

WORKDIR=$(mktemp -d)
trap 'rm -rf "${WORKDIR}"' EXIT

gh repo clone "${REPO}" "${WORKDIR}/bundle" -- --depth 1 --quiet
cd "${WORKDIR}/bundle"
DEFAULT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
cp composer.lock "${WORKDIR}/before.lock"

mapfile -t PACKAGES < <(
	jq --raw-output '(.packages + ."packages-dev")[].name | select(startswith("wp-cli/") and . != "wp-cli/wp-cli")' composer.lock
)

echo "Updating ${#PACKAGES[@]} packages in the composer.lock of ${REPO}..." >&2
COMPOSER_ROOT_VERSION="dev-${DEFAULT_BRANCH}" composer update "${PACKAGES[@]}" \
	--no-install --no-scripts --no-plugins --no-interaction --no-progress >&2

if git diff --quiet -- composer.lock; then
	echo "The composer.lock of ${REPO} is up to date." >&2
	exit 0
fi

# One "- name: old → new" line per package whose version or commit changed.
CHANGES=$(
	jq --null-input --raw-output \
		--slurpfile before "${WORKDIR}/before.lock" \
		--slurpfile after composer.lock '
		def versions: (.packages + ."packages-dev") | map({ key: .name, value: (.version + " (" + .source.reference[0:7] + ")") }) | from_entries;
		($before[0] | versions) as $old
		| ($after[0] | versions) as $new
		| ($old + $new | keys[])
		| select($old[.] != $new[.])
		| "- `\(.)`: \($old[.] // "new") → \($new[.] // "removed")"
	'
)

echo "${CHANGES}" >&2

if [[ "${DRY_RUN}" == true ]]; then
	exit 0
fi

BODY=$(
	cat <<-EOF
		Updates \`composer.lock\` so the bundle ships the latest release of every bundled package:

		${CHANGES}

		Generated with \`.maintenance/refresh-bundle-lock.sh\` from wp-cli/wp-cli-dev. Only \`composer.lock\` changes.
	EOF
)

git checkout --quiet -b "${BRANCH}"
git add composer.lock
git commit --quiet -m "${TITLE}"
git push --quiet --force origin "${BRANCH}"

PR_NUMBER=$(gh pr list --repo "${REPO}" --head "${BRANCH}" --state open --json number --jq '.[0].number // empty')
if [[ -z "${PR_NUMBER}" ]]; then
	gh pr create --repo "${REPO}" --base "${DEFAULT_BRANCH}" --head "${BRANCH}" \
		--title "${TITLE}" --body "${BODY}" --label "scope:distribution"
else
	gh pr edit "${PR_NUMBER}" --repo "${REPO}" --title "${TITLE}" --body "${BODY}" >/dev/null
	gh pr view "${PR_NUMBER}" --repo "${REPO}" --json url --jq .url
fi
