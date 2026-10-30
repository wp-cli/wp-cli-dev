wp-cli/wp-cli-dev
=================

Sets up a WP-CLI development environment that allows for easy development across all packages.

This allows easy development across all packages and contains additional maintenance commands that simplify repository chores and the release process.

[![Testing](https://github.com/wp-cli/wp-cli-dev/actions/workflows/testing.yml/badge.svg)](https://github.com/wp-cli/wp-cli-dev/actions/workflows/testing.yml)

Quick links: [Installation](#installation) | [Development](#development) | [Using](#using) | [Contributing](#contributing) | [Support](#support)

## Installation

If you normally use WP-CLI on your web host or via Brew, you're most likely using the Phar executable (`wp-cli.phar`). This Phar executable file is the "built", singular version of WP-CLI. It is compiled from a couple dozen repositories in the WP-CLI GitHub organization.

In order to make code changes to WP-CLI, you'll need to set up this `wp-cli-dev` development environment on your local machine. The setup process will:

1. Clone all relevant packages from the `wp-cli` GitHub organization into the `wp-cli-dev` folder, and
2. Install all Composer dependencies for a complete `wp-cli-bundle` setup, while symlinking all of the previously cloned packages into the Composer `vendor` folder.
3. Symlink all folder in `vendor` into corresponding `vendor` folders in each repository, thus making the centralized functionality based on Composer available in each repository subfolder.

Before you can proceed further, you'll need to make sure you have [Composer](https://getcomposer.org/) 2.9 or later, PHP, and a functioning MySQL or MariaDB server on your local machine.

Composer 2.9 and later refuse to install package versions with known security advisories, which protects the development environment from vulnerable dependencies. That is why WP-CLI doesn't rely on the `roave/security-advisories` package for this anymore. Older Composer versions install without this check. See the [Composer 2.9 release post](https://blog.packagist.com/composer-2-9/) for details.

Once the prerequisites are met, clone the GitHub repository and run the installation process:

```bash
git clone https://github.com/wp-cli/wp-cli-dev wp-cli-dev
cd wp-cli-dev
composer install
composer prepare-tests
```

## Development

Every subfolder is a proper clone of the corresponding GitHub repository. This means that you can create new branches, make your changes, commit to the new branch and then submit as pull-request, all from within these folders.

Unless you have commit access to the repository, you'll need to fork the repository in order to push your feature branch. [GitHub's CLI](https://github.com/cli/cli) is pretty helpful for this:

```bash
cd core-command
gh repo fork
```

As the folders are also symlinked into the Composer `vendor` folder, you will always have the latest changes available when running WP-CLI through the `vendor/bin/wp` executable.

Running `composer install` or `composer update` also fetches every clone and fast-forwards the ones that are clean and on their default branch. Clones with uncommitted changes or on another branch are only fetched, so work in progress is never switched away from.

The `wp maintenance` commands don't need the full environment: any `wp` executable picks them up through `wp-cli.yml` when run from this folder. They authenticate with the GitHub CLI when it is logged in, and with the `GH_TOKEN` or `GITHUB_TOKEN` environment variable otherwise. Run `wp maintenance doctor` to check the setup.

To find out which packages have unreleased changes, run `wp maintenance release plan --bundle`. It makes no changes, and for each package it says whether a milestone is ready for `wp maintenance release generate` or what a maintainer still needs to decide, such as which milestone a merged pull request belongs to.

## Using

This package implements the following commands:

### wp maintenance

Provides tools to manage the WP-CLI GitHub organization and the release process.

~~~
wp maintenance
~~~





### wp maintenance contrib-list

Lists all contributors to this release.

~~~
wp maintenance contrib-list [<repo>] [<milestone>...] [--release=<version>] [--bundle-ref=<ref>] [--format=<format>]
~~~

Only authors of merged pull requests count; bots are left out.

**OPTIONS**

	[<repo>]
		Name of the repository to list the contributors for. If no user/org
		was provided, 'wp-cli' org is assumed. If no repo is passed, the
		contributors to the entire org since the last bundle release are listed.

	[<milestone>...]
		Name of one or more milestones to list the contributors for. If none
		are passed, the current open one is assumed.

	[--release=<version>]
		Version of the bundle release, e.g. 3.0.0. Uses the milestone with
		that title in wp-cli/wp-cli-bundle, wp-cli/wp-cli and wp-cli/handbook
		instead of the currently open one, and reads the bundled packages from
		the composer.lock at the release tag of wp-cli/wp-cli-bundle. Only
		applies when no repo is passed.

	[--bundle-ref=<ref>]
		Branch, tag or commit of wp-cli/wp-cli-bundle whose composer.lock lists
		the packages and versions shipped in this release. Defaults to the
		release tag when --release is passed and the tag exists, and to the
		default branch otherwise. Only applies when no repo is passed.

	[--format=<format>]
		Render output in a specific format.
		---
		default: markdown
		options:
		  - markdown
		  - html
		  - json
		---



### wp maintenance doctor

Checks whether this environment can run the maintenance commands.

~~~
wp maintenance doctor [--format=<format>]
~~~

Reports which GitHub credentials are in use and for which account, the
remaining API rate limit, and whether the tools the maintenance scripts
rely on are available. Exits with an error code when a check fails.

Credentials come from the GitHub CLI when it is logged in, and from the
GH_TOKEN or GITHUB_TOKEN environment variables otherwise.

**OPTIONS**

	[--format=<format>]
		Render output in a specific format. Defaults to a table in a terminal
		and to JSON otherwise.
		---
		options:
		  - table
		  - json
		---

**EXAMPLES**

    $ wp maintenance doctor
    +-------------+--------+---------------------------------------------+
    | check       | status | message                                     |
    +-------------+--------+---------------------------------------------+
    | credentials | ok     | Using the login of the GitHub CLI (gh).     |
    | account     | ok     | Authenticated as octocat.                   |
    ...



### wp maintenance milestones-after

Retrieves the milestones that were closed after a given milestone.

~~~
wp maintenance milestones-after <repo> <milestone> [--format=<format>]
~~~

**OPTIONS**

	<repo>
		Name of the repository to fetch the milestones for.

	<milestone>
		Milestone to serve as threshold.

	[--format=<format>]
		Render output in a specific format.
		---
		default: list
		options:
		  - list
		  - json
		---



### wp maintenance milestones-since

Retrieves the milestones that were closed for a given repository after a

~~~
wp maintenance milestones-since <repo> <date> [--format=<format>]
~~~

specific date threshold.

**OPTIONS**

	<repo>
		Name of the repository to fetch the milestones for.

	<date>
		Threshold date to filter by.

	[--format=<format>]
		Render output in a specific format.
		---
		default: list
		options:
		  - list
		  - json
		---



### wp maintenance release



~~~
wp maintenance release
~~~





### wp maintenance release close-released

Close the already released milestones.

~~~
wp maintenance release close-released [<repo>...] [--bundle] [--all]
~~~

**OPTIONS**

	[<repo>...]
		Name(s) of the repository to close the milestones for. If no user/org was
		provided, 'wp-cli' org is assumed.

	[--bundle]
		Close the milestones for the entire bundle.

	[--all]
		Close the milestones for all repositories in the wp-cli organization.



### wp maintenance release generate

Generate a new release out of an open milestone

~~~
wp maintenance release generate [<repo>...] [--bundle] [--all] [--milestone=<milestone>] [--notes-file=<file>] [--yes]
~~~

Tags the default branch with the version of the milestone, publishes a
release whose notes list the pull requests merged for that milestone,
and closes the milestone.

A milestone is skipped, with a warning, when it still has open issues or
pull requests, has no merged pull requests, is not a version newer than
the latest tag, or was already tagged. When a repository has several
milestones that could be released, it is skipped unless --milestone says
which one, as they would all tag the same commit.

It is also skipped unless `wp maintenance release plan` reports it as
ready for that milestone: the tag ships everything merged into the
default branch since the latest tag, so all of it has to belong to the
milestone, and all of the milestone's pull requests have to be in it.

Run `wp maintenance release plan` first to see what is ready.

**OPTIONS**

	[<repo>...]
		Name(s) of the repository to generate a release for. If no user/org was
		provided, 'wp-cli' org is assumed.

	[--bundle]
		Generate releases for the entire bundle.

	[--all]
		Generate releases for all repositories in the wp-cli organization.

	[--milestone=<milestone>]
		Only release the milestone with this title. Needs a single repository.

	[--notes-file=<file>]
		Use the contents of this file as release notes, instead of the
		generated list of pull requests. Useful to publish notes that were
		reviewed and tidied up first. Needs --milestone.

	[--yes]
		Publish without asking for confirmation.

**EXAMPLES**

    # Review the generated notes, then publish them after fixing a typo.
    $ wp maintenance release-notes cache-command 3.0.1 --source=pull-request > notes.md
    $ wp maintenance release generate cache-command --milestone=3.0.1 --notes-file=notes.md --yes



### wp maintenance release plan

Checks which repositories have unreleased changes, and whether their

~~~
wp maintenance release plan [<repo>...] [--bundle] [--all] [--format=<format>]
~~~

milestones are ready to release them.

Compares the default branch of each repository against its latest
version tag and looks at the pull requests merged since. A repository is
`up_to_date` when none of them calls for a release, `ready` when they
all sit on one open milestone that `release generate` can release, and
`needs_human` when a maintainer has to decide something first, such as
which milestone a pull request belongs to. The blockers say what.

Routine pull requests by Dependabot or GitHub Actions only count when
they are on a milestone.

Makes no changes.

**OPTIONS**

	[<repo>...]
		Name(s) of the repository to check. If no user/org was provided,
		'wp-cli' org is assumed.

	[--bundle]
		Check every package of the bundle.

	[--all]
		Check all repositories in the wp-cli organization.

	[--format=<format>]
		Render output in a specific format. Defaults to a table in a terminal
		and to JSON otherwise. The JSON lists the unreleased pull requests and
		the blockers of each repository as well.
		---
		options:
		  - table
		  - json
		---

**EXAMPLES**

    $ wp maintenance release plan cache-command db-command eval-command
    +----------------------+-------------+------------+---------+------------+
    | repo                 | status      | latest_tag | release | blockers   |
    +----------------------+-------------+------------+---------+------------+
    | wp-cli/cache-command | ready       | v3.0.0     | v3.0.1  |            |
    | wp-cli/db-command    | needs_human | v3.0.1     |         | open_items |
    | wp-cli/eval-command  | up_to_date  | v3.0.1     |         |            |
    +----------------------+-------------+------------+---------+------------+



### wp maintenance release-date

Retrieves the date a given release for a repository was published at.

~~~
wp maintenance release-date <repo> <release>
~~~

**OPTIONS**

	<repo>
		Name of the repository to fetch the release date for. If no user/org
		was provided, 'wp-cli' org is assumed.

	<release>
		Name of the release to fetch the release date for.



### wp maintenance release-notes

Gets the release notes for one or more milestones of a repository.

~~~
wp maintenance release-notes [<repo>] [<milestone>...] [--release=<version>] [--bundle-ref=<ref>] [--source=<source>] [--format=<format>]
~~~

**OPTIONS**

	[<repo>]
		Name of the repository to fetch the release notes for. If no user/org
		was provided, 'wp-cli' org is assumed. If no repo is passed, release
		notes for the entire org state since the last bundle release are fetched.

	[<milestone>...]
		Name of one or more milestones to fetch the release notes for. If none
		are passed, the current open one is assumed.

	[--release=<version>]
		Version of the bundle release, e.g. 3.0.0. Uses the milestone with
		that title in wp-cli/wp-cli-bundle, wp-cli/wp-cli and wp-cli/handbook
		instead of the currently open one, and reads the bundled packages from
		the composer.lock at the release tag of wp-cli/wp-cli-bundle. Only
		applies when no repo is passed.

	[--bundle-ref=<ref>]
		Branch, tag or commit of wp-cli/wp-cli-bundle whose composer.lock lists
		the packages and versions shipped in this release. Defaults to the
		release tag when --release is passed and the tag exists, and to the
		default branch otherwise. Only applies when no repo is passed.

	[--source=<source>]
		Choose source from where to copy content.
		---
		default: release
		options:
		  - release
		  - pull-request
		---

	[--format=<format>]
		Render output in a specific format.
		---
		default: markdown
		options:
		  - markdown
		  - html
		  - json
		---



### wp maintenance replace-label

Replaces a label with a different one, and optionally deletes the old

~~~
wp maintenance replace-label <repo> <old-label> <new-label> [--delete]
~~~

label.

**OPTIONS**

	<repo>
		Name of the repository you want to replace a label for.

	<old-label>
		Old label to replace on all issues and pull requests, open or closed.

	<new-label>
		New label to replace it with.

	[--delete]
		Delete the old label after the operation is complete.

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.

For a more thorough introduction, [check out WP-CLI's guide to contributing](https://make.wordpress.org/cli/handbook/contributing/). This package follows those policy and guidelines.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/wp-cli/wp-cli-dev/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/wp-cli/wp-cli-dev/issues/new). Include as much detail as you can, and clear steps to reproduce if possible. For more guidance, [review our bug report documentation](https://make.wordpress.org/cli/handbook/bug-reports/).

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/wp-cli/wp-cli-dev/issues/new) to discuss whether the feature is a good fit for the project.

Once you've decided to commit the time to seeing your pull request through, [please follow our guidelines for creating a pull request](https://make.wordpress.org/cli/handbook/pull-requests/) to make sure it's a pleasant experience. See "[Setting up](https://make.wordpress.org/cli/handbook/pull-requests/#setting-up)" for details specific to working on this package locally.

### License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Support

GitHub issues aren't for general support questions. For support resources and next steps, see the WP-CLI Support page: https://make.wordpress.org/cli/handbook/support/


