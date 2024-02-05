# deployer-recipes
Deployer recipes used at [Heimrich & Hannot GmbH](https://www.heimrich-hannot.de).

## Install

To use our recipes, require this package with composer.
```shell
composer require --dev heimrichhannot/deployer-recipes
```

If you haven't already installed [Deployer](https://deployer.org/), do so with the following command.
```shell
composer require --dev deployer/deployer
```

## Usage with Contao 4.13+

Create a `deploy.php` file in your Contao project root directory
and customize the following content according to your needs.
```php
<?php # deploy.php

namespace Deployer;

date_default_timezone_set('Europe/Berlin');

import(__DIR__ . '/vendor/heimrichhannot/deployer-recipes/autoload.php');
recipe('contao');

set('rsync_src', __DIR__);

host('www.example.org')
    ->setPort(22)
    ->set('remote_user', 'www_data')
    ->set('http_user', 'www_data')
    ->set('public_dir', 'public')
    ->set('deploy_path', '/usr/www/users/{{remote_user}}/docroot')
    ->set('bin/php', 'php82')
    ->set('bin/composer', '{{bin/php}} {{deploy_path}}/composer.phar')
    ->set('release_name', fn() => date('y-m-d_H-i-s'))
    /** In case ACL is unavailable, use chmod instead */
    // ->set('writable_mode', 'chmod')
;

/** Optional: Add project-specific files */
// add('project_files', [
//     'config/routes.yaml',
//     'translations',
// ]);

/** Optional: Remove values from a variable */
// remove('project_files', [
//    'files/themes',
//    'templates'
// ]);

/** Optional: Add project-specific files to exclude from deploy */
// add('exclude', [
//     '.githooks',
// ]);

/** Optional: Add a shared .htpasswd or any other file */
/// add('shared_files', [
//     '{{public_path}}/.htpasswd'
// ]);

/** Optional: Add yarn build task */
// before('deploy', 'ddev:yp');

/** Optional: Ask confirmation before going live */
// before('deploy', 'ask_confirm_prod');
```

### Work in Progress

These templates are still work in progress and not yet fully implemented. Use with caution or not at all. 

```php
/** WIP: Deploy an htaccess file which will be renamed to .htaccess */
set('htaccess_filename', '.htaccess.prod');
after('deploy:shared', 'deploy:htaccess');
```
