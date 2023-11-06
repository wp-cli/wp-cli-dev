If you normally use WP-CLI on your web host or via Brew, you're most likely using the Phar executable (`wp-cli.phar`). This Phar executable file is the "built", singular version of WP-CLI. It is compiled from a couple dozen repositories in the WP-CLI GitHub organization.

In order to make code changes to WP-CLI, you'll need to set up this `wp-cli-dev` development environment on your local machine. The setup process will:

1. Clone all relevant packages from the `wp-cli` GitHub organization into the `wp-cli-dev` folder, and
2. Install all Composer dependencies for a complete `wp-cli-bundle` setup, while symlinking all of the previously cloned packages into the Composer `vendor` folder.
3. Symlink all folder in `vendor` into corresponding `vendor` folders in each repository, thus making the centralized functionality based on Composer available in each repository subfolder.

Before you can proceed further, you'll need to make sure you have [Composer](https://getcomposer.org/), PHP, and a functioning MySQL or MariaDB server on your local machine.

Once the prequisites are met, clone the GitHub repository and run the installation process:

```bash
git clone https://github.com/wp-cli/wp-cli-dev wp-cli-dev
cd wp-cli-dev
composer install
composer prepare-tests
```
