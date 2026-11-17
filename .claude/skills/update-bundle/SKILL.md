---
name: update-bundle
description: Release every bundled WP-CLI package that has unreleased changes, then refresh the wp-cli/wp-cli-bundle lockfile and the automated tests until they are green. Use when asked to "update the bundle", cut package releases across the bundle, or refresh the bundle's lock files. Asks the maintainer every milestone question in one go, then gets a single approval before tagging anything.
---

# Update the bundle

Takes the bundle from "packages have unreleased changes" to "every package is
tagged, the bundle lockfile ships those tags, and the automated tests are
green". Stops and hands over to the maintainer when something bigger than a
routine fix turns up.

Run everything from the root of this `wp-cli-dev` checkout, where any `wp`
executable picks up the `wp maintenance` commands through `wp-cli.yml`. Set
`NO_COLOR=1` for every command whose output you read back.

The run has four phases. **Nothing is written to GitHub before the
maintainer's answers in phase 2. Nothing is tagged before their approval in
phase 3.**

1. Survey (read-only)
2. Milestone questions, then milestone changes
3. Release proposal, approval, then tagging
4. Lockfiles and tests: framework, bundle, automated tests

Keep a running log in a scratch file, such as `update-bundle-state.md` in the
session's scratch or temp folder (not in this repository). Record the answers,
the tags created, and the PRs and workflow runs you are watching. Re-read it
after a context compaction instead of re-asking the maintainer. `$SCRATCH`
below stands for that folder.

## Phase 1: Survey

```bash
wp maintenance doctor
wp maintenance release plan --bundle --format=json > "$SCRATCH/plan.json"
```

Stop if `doctor` reports a failed check. Stop as well if it reports `gh`,
`jq` or `composer` as missing: `doctor` only warns about them, but this skill
needs all three, and finding out in phase 4 would leave tags without lockfile
updates. The plan takes a few hundred API requests, so the rate limit `doctor`
shows should leave room for that.

`--bundle` covers every package in the composer.lock of wp-cli/wp-cli-bundle:
the `*-command` packages, plus wp-cli-tests, php-cli-tools, spyc and
wp-config-transformer. The wp-cli/wp-cli framework is not tagged here. The
bundle tracks its `main` branch, see phase 4.

For each repository, the JSON holds:

| Field | Meaning |
| --- | --- |
| `status` | `up_to_date`, `ready` (`release.tag` can be generated as is) or `needs_human` |
| `latest_tag` | The tag the default branch is compared against |
| `pull_requests` | Merged since `latest_tag` that call for a release. Leaves out Dependabot and GitHub Actions PRs unless they are on a milestone, and PRs that only touch `.github/`, `README.md`, `AGENTS.md`, `.actrc` or `.editorconfig`. |
| `blockers` | What `needs_human` is about, by `code`, see below |
| `open_items` | Issues/PRs still open on the milestone the unreleased changes are on |
| `open_pull_requests` | Open, non-draft, non-bot PRs that have no open milestone |
| `direct_commits` | Commits pushed without a PR that change more than those development files. `null` when there are too many commits since `latest_tag` for GitHub to compare; check those by hand |
| `open_milestones` | Open milestones that are a version newer than `latest_tag`, lowest first |

## Phase 2: Milestone questions

Work out every question first, across all repositories. Then ask them all,
grouped into as few `AskUserQuestion` calls as possible: up to 4 questions
per call and up to 4 options per question, with "Other" always added
automatically. Put the repository and PR numbers in every question, since the
maintainer may see only the dialog. Only start the next call once the previous
one has its answers, and make no change until every question is answered.

Name the milestones like this:

- **R**, the release milestone of a repository: `release.milestone` when
  `ready`. Otherwise the milestone the unreleased `pull_requests` are on.
  Otherwise the lowest of `open_milestones`. Otherwise a new milestone, one
  patch version above `latest_tag`.
- **N**, the next milestone after R: the lowest of `open_milestones` above R.
  Otherwise a new milestone, one patch version above R.

Choosing a version is the maintainer's call. Propose the patch bump, and offer
a minor bump as a second option when a merged PR adds a command, a subcommand
or a flag.

Questions by blocker or field:

- **`no_milestone`** (merged PRs without one): "Ship #A, #B of `<repo>` in R?"
  The options are *Put on R* or *Skip this repo's release*. They are merged
  already, so a tag ships them either way, and leaving them off R only drops
  them from the release notes.
- **`released_milestone`** (merged PRs on a milestone that is closed or tagged
  already): the same question. Answering *Put on R* moves them.
- **`open_items`**: "`<repo>` R still has #X (open PR, by …) open. Wait for it
  or defer it?" The options are *Defer to N and release R now* or *Wait, so
  no release of `<repo>`*. Mention drafts and issues as such.
- **`several_milestones`**: "The unreleased changes of `<repo>` are on X and
  Y. Which one should the release be?" The options are each milestone, plus
  *Skip*. All the PRs end up on the chosen one.
- **`open_pull_requests`**: one multi-select question per repository, with up
  to 4 PRs per question (split longer lists): "Which open PRs of `<repo>`
  should go on M?" M is N when R is released in this run, and R otherwise.
  Leaving a PR unchecked leaves it alone. Open PRs never block a release.
- **`direct_commits`**: "`<repo>` has commits pushed without a PR, which R
  would ship without mentioning them: `<sha> <message>`. Release anyway?" A
  *no* excludes the repository from phase 3, even though its plan stays
  `ready`. Record the exclusion in the log. If a repository has direct commits
  but no merged PR, `release generate` can't release it. Report it for the
  maintainer to handle by hand.
- **`invalid_version`, `no_version_tag`**: no question. List them in the final
  report as needing manual attention.

Then apply the answers. One call puts any number of items on a milestone, and
creates it if needed:

```bash
wp maintenance milestone assign <repo> <milestone> <number>... --create
```

Then re-run `wp maintenance release plan <repo>... --format=json` for the
repositories you changed. A repository the maintainer wanted released that is
still not `ready` points to a misread answer or a new blocker. Ask about it
instead of working around it.

## Phase 3: Release proposal and approval

Every repository that is now `ready` and that the maintainer did not exclude
in phase 2 gets a proposal. Save its plan JSON in the log as the approved
snapshot. Gather all of them
before asking. For each one, preview the notes:

```bash
wp maintenance release-notes <repo> <milestone> --source=pull-request
```

Then show a single overview as a table, with these columns: repository,
`latest_tag` → new tag, number of PRs, a one-line summary of the notes, and
anything notable such as direct commits or deferred items. Below it, list what
phase 4 will do. Then ask for approval in one `AskUserQuestion` call, which
also settles how to merge in phase 4:

1. "Tag and release these N packages?" *Release all* / *Release all except…
   (say which in Other)* / *Cancel*.
2. "The bundle requires a review before merging. How should the lockfile PRs
   be merged once green?" *Approve the bot's PR and merge; admin-merge my own*
   / *Approve the bot's PR and merge; ask a reviewer for mine* / *Leave all
   merges to me*.

Then release each approved package. Right before each one, re-run
`wp maintenance release plan <repo> --format=json` and compare it with the
approved snapshot: the same `latest_tag`, `release`, `pull_requests` and
`direct_commits`. If anything was merged or pushed since, don't tag it. Ask
again about that repository once the others are released.

```bash
wp maintenance release generate <repo> --milestone=<milestone> --yes
```

The command re-checks the plan and skips the release, with a warning, if
anything changed in the meantime. Report every skip. Do not force it. Check
each release afterwards with `gh release view v<milestone> --repo <repo>`.

Before phase 4, wait until Packagist lists every new version, or Composer
won't find them. It usually takes a minute or two:

```bash
curl -fsS "https://repo.packagist.org/p2/wp-cli/<package>.json" | jq -r '.packages[][].version' | head -3
```

The package name can differ from the repository: wp-cli/spyc is
`wp-cli/mustangostang-spyc`. If a version is missing after about 15 minutes,
report it to the maintainer.

## Phase 4: Lockfiles and tests

Do the steps in this order. Each one updates `composer.lock` of the bundle,
and running them at the same time makes the PRs conflict.

### 4a. Framework: the "Update wp-cli framework" workflow of the bundle

The wp-cli/wp-cli repository has no lockfile of its own. Its "lock refresh" is
the `update-framework.yml` workflow of wp-cli/wp-cli-bundle. It runs
`composer update wp-cli/wp-cli --with-all-dependencies`, which also brings in
new releases of php-cli-tools and spyc, and opens or updates the
`update-framework` PR. It runs daily and on every push to the bundle's `main`.

First note the commit the lockfile should end up with:

```bash
gh api repos/wp-cli/wp-cli/commits/main --jq .sha
```

Then pick a run that started after the last tag from phase 3, active or
finished. If there is none, trigger one:

```bash
gh run list --repo wp-cli/wp-cli-bundle --workflow update-framework.yml --limit 5 \
  --json databaseId,event,status,conclusion,createdAt
gh workflow run update-framework.yml --repo wp-cli/wp-cli-bundle --ref main
```

Either way, wait for that run to finish, and look at nothing else until it
does. A run still in progress may be rewriting the PR. If it fails, read its
log and bring the maintainer in.

Once it has succeeded, find the PR with `gh pr list --repo
wp-cli/wp-cli-bundle --head update-framework --state open`. Check that its
lockfile pins `wp-cli/wp-cli` at the commit you noted, or main's lockfile
does when there is no PR:

```bash
gh api "repos/wp-cli/wp-cli-bundle/contents/composer.lock?ref=<update-framework|main>" \
  --jq '.content | @base64d | fromjson | .packages[] | select(.name == "wp-cli/wp-cli") | .source.reference'
```

A run's start time does not prove it saw a commit, since a commit can reach
`main` after the run started even when it is dated earlier. So if the pinned
commit differs, trigger one more run and repeat. No PR, with the commit you
noted on main, means the lockfile was up to date already. Otherwise babysit
the PR, see below.

### 4b. Bundle: bundled packages in the bundle's composer.lock

Start this only after the framework PR is merged or turned out to be
unnecessary. Nothing in the bundle updates the bundled packages as a whole on
its own. Dependabot opens one PR per package, and they close themselves once
the lockfile has caught up. Preview, then open the PR:

```bash
.maintenance/refresh-bundle-lock.sh --dry-run
.maintenance/refresh-bundle-lock.sh
```

The script moves only the `wp-cli/*` packages, never the framework or
third-party dependencies, and only touches `composer.lock`. If its
`update-bundled-packages` PR is open already, the script updates that PR and
prints its URL. No output on stdout means the lockfile is up to date already.
Each package should end up on the tag created in phase 3. If one doesn't,
find out why before merging, usually a constraint in the bundle's
`composer.json`.

If Composer cannot resolve the update, for example because a new release
needs a newer third-party dependency, that is a bigger issue. Stop and report
it.

### 4c. Automated tests: wp-cli/automated-tests

Check `config.lock` in its `composer.json` first.

- **`false`, as is the case today**: there is no lockfile. Every run resolves
  the bundle's `dev-main` afresh, so refreshing means running the suite once
  the bundle PR is merged:

  ```bash
  gh workflow run testing.yml --repo wp-cli/automated-tests --ref main
  ```

  Then babysit that run the way a PR is babysat. It fans out to about 150 jobs
  and takes a while.
- **A committed `composer.lock`**: update it on a branch with
  `composer update 'wp-cli/*' --no-install`, open a PR, and babysit it.

## Babysitting a PR or run

**Waiting.** Use the harness's own facility to watch PRs or wait on background
work if it has one. Otherwise run `gh pr checks <n> --repo <repo> --watch`, or
`gh run watch <run-id> --repo <repo> --exit-status`, in the background. Don't
poll in a tight loop.

**Triaging each failing check.** Read the job log first, with `gh run view
<run-id> --repo <repo> --log-failed`, and classify the failure:

- **Pre-existing**: the same check fails on the latest run of the default
  branch too, so the PR didn't cause it. Example: "Audit locked dependencies"
  flagging an advisory in a third-party package. To tell, compare
  `wp maintenance dependencies audit --ref=<pr-branch>` with a run of the
  same command against the default branch. It also shows whether the affected
  packages ship in the Phar or are only needed for development. Ask the
  maintainer whether to merge anyway. Don't fix it as part of this run.
- **Flaky**: a network timeout, a failed download from WordPress.org or
  GitHub, MySQL not starting, a rate limit, or a runner lost mid-job. Re-run
  the failed jobs once with `gh run rerun <run-id> --repo <repo> --failed`. A
  second identical failure is real.
- **Minor, fix it**: the PR is out of date or conflicts with `main`, which
  re-running the workflow or the script fixes. Also small fixes confined to
  the PR's own files. Push the fix to the PR branch.
- **Bigger, rope the human in**: a Behat or PHPUnit failure in a package's
  behaviour, which may be a regression in a new release, a Composer
  resolution failure, an audit failure that would need a third-party update
  past the Dependabot cooldown, or anything that needs a code change in
  another repository or another release.

  Stop the run there. Report the PR or run link, the failing jobs, a short
  log excerpt, the package and PR most likely responsible, and the fixes you
  see. Phases that don't depend on the blocked one may go on, but 4a → 4b →
  4c depend on each other.

**Merging.** Only with every required check green, or with the maintainer's
explicit go-ahead on a pre-existing failure. Follow the merge choice from
phase 3:

```bash
gh pr review <n> --repo <repo> --approve     # bot-authored PRs only
gh pr merge  <n> --repo <repo> --squash      # add --admin only for the maintainer's own PRs, if allowed
```

Never approve a PR the authenticated account wrote itself. If merging is left
to the maintainer, or a reviewer is needed, say so in the report and wait.

## Final report

End with:

- the releases, with tags and links, and every repository skipped, with the
  reason,
- the milestone changes made,
- the PRs merged and the automated-tests run, with links and conclusions,
- anything waiting on the maintainer, and what exactly is needed.
