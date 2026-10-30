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
