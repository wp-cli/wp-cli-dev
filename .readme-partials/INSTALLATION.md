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
