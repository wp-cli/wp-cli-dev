Every subfolder is a proper clone of the corresponding GitHub repository. This means that you can create new branches, make your changes, commit to the new branch and then submit as pull-request, all from within these folders.

Unless you have commit access to the repository, you'll need to fork the repository in order to push your feature branch. [GitHub's CLI](https://github.com/cli/cli) is pretty helpful for this:

```bash
cd core-command
gh repo fork
```

As the folders are also symlinked into the Composer `vendor` folder, you will always have the latest changes available when running WP-CLI through the `vendor/bin/wp` executable.
