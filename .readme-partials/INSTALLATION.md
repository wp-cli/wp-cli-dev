Clone this repository onto your hard drive and then use Composer to install all dependencies:

```
git clone https://github.com/wp-cli/wp-cli-dev
cd wp-cli-dev
composer install
```

This will:

1. Clone all relevant packages from the `wp-cli` GitHub organization into the `wp-cli-dev` folder, and
2. Install all Composer dependencies for a complete `wp-cli-bundle` setup, while symlinking all of the previously cloned packages into the Composer `vendor` folder.
