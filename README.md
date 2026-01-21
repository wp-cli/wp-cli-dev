wp-cli/wp-cli-dev
=================

Sets up a WP-CLI development environment that allows for easy development across all packages.

This allows easy development across all packages and contains additional maintenance commands that simplify repository chores and the release process.



Quick links: [Installation](#installation) | [Development](#development) | [Using](#using) | [Contributing](#contributing) | [Support](#support)

## Installation

If you normally use WP-CLI on your web host or via Brew, you're most likely using the Phar executable (`wp-cli.phar`). This Phar executable file is the "built", singular version of WP-CLI. It is compiled from a couple dozen repositories in the WP-CLI GitHub organization.

In order to make code changes to WP-CLI, you'll need to set up this `wp-cli-dev` development environment on your local machine. The setup process will:

1. Clone all relevant packages from the `wp-cli` GitHub organization into the `wp-cli-dev` folder, and
2. Install all Composer dependencies for a complete `wp-cli-bundle` setup, while symlinking all of the previously cloned packages into the Composer `vendor` folder.
3. Symlink all folder in `vendor` into corresponding `vendor` folders in each repository, thus making the centralized functionality based on Composer available in each repository subfolder.

Before you can proceed further, you'll need to make sure you have [Composer](https://getcomposer.org/), PHP, and a functioning MySQL or MariaDB server on your local machine.

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
wp maintenance contrib-list [<repo>] [<milestone>...] [--format=<format>]
~~~

Run within the main WP-CLI project repository.

**OPTIONS**

	[<repo>]
		Name of the repository to fetch the release notes for. If no user/org
		was provided, 'wp-cli' org is assumed. If no repo is passed, release
		notes for the entire org state since the last bundle release are fetched.

	[<milestone>...]
		Name of one or more milestones to fetch the release notes for. If none
		are passed, the current open one is assumed.

	[--format=<format>]
		Render output in a specific format.
		---
		default: markdown
		options:
		  - markdown
		  - html
		---



### wp maintenance milestones-after

Retrieves the milestones that were closed after a given milestone.

~~~
wp maintenance milestones-after <repo> <milestone>
~~~

**OPTIONS**

	<repo>
		Name of the repository to fetch the milestones for.

	<milestone>
		Milestone to serve as threshold.



### wp maintenance milestones-since

Retrieves the milestones that were closed for a given repository after a

~~~
wp maintenance milestones-since <repo> <date>
~~~

specific date threshold.

**OPTIONS**

	<repo>
		Name of the repository to fetch the milestones for.

	<date>
		Threshold date to filter by.



### wp maintenance release-date

Retrieves the date a given release for a repository was published at.

~~~
wp maintenance release-date <repo> <release>
~~~

**OPTIONS**

	<repo>
		Name of the repository to fetch the release notes for. If no user/org
		was provided, 'wp-cli' org is assumed.

	<release>
		Name of the release to fetch the release notes for.



### wp maintenance release-notes

Gets the release notes for one or more milestones of a repository.

~~~
wp maintenance release-notes [<repo>] [<milestone>...] [--source=<source>] [--format=<format>]
~~~

**OPTIONS**

	[<repo>]
		Name of the repository to fetch the release notes for. If no user/org
		was provided, 'wp-cli' org is assumed. If no repo is passed, release
		notes for the entire org state since the last bundle release are fetched.

	[<milestone>...]
		Name of one or more milestones to fetch the release notes for. If none
		are passed, the current open one is assumed.

	[--source=<source>]
		Choose source from where to copy content.
		---
		default: release
		options:
		  - release
		  - pull-request

	[--format=<format>]
		Render output in a specific format.
		---
		default: markdown
		options:
		  - markdown
		  - html
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
		Old label to replace on all issues.

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

## Support

GitHub issues aren't for general support questions, but there are other venues you can try: https://wp-cli.org/#support


