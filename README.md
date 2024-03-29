# deployer-recipes
Deployer recipes used at [Heimrich & Hannot GmbH](https://www.heimrich-hannot.de).

## Install

To use our recipes, require this package with composer.
```shell
composer require --dev heimrichhannot/deployer-recipes
```

It is _not_ required to install [Deployer](https://deployer.org/) separately, but you may do so with the following command.
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
    ->setRemoteUser('www_data')
    ->set('http_user', 'www_data')
    ->set('public_dir', 'public')
    ->set('deploy_path', '/usr/www/users/{{remote_user}}/docroot')
    ->set('bin/php', 'php82')
    ->set('release_name', fn() => date('y-m-d_H-i-s'))
    /** In case ACL is unavailable, use chmod instead */
    // ->set('writable_mode', 'chmod')
;

/** @example Add project-specific files */
// add('project_files', [
//     'config/routes.yaml',
//     'translations',
// ]);

/** @example Remove values from a variable */
// remove('project_files', [
//    'files/themes',
//    'templates'
// ]);

/** @example Add project-specific files to exclude from deploy */
// add('exclude', [
//     '.githooks',
// ]);

/** @example Add a shared .htpasswd or any other file */
// add('shared_files', [
//     '{{public_path}}/.htpasswd'
// ]);

/** @example Ask confirmation before running migrations */
// set('ask_confirm_migrate', true);

/** @example Do not create backup on migration */
// set('create_db_backup', false);

/** @example Add yarn build task */
// before('deploy', 'ddev:yp');

/** @example Ask confirmation before going live */
// before('deploy', 'ask_confirm_prod');

/** @example Deploy `files/themes`, which are shared and not updated by default */
// after('deploy:shared', 'deploy:themes');
```

## Setup multiple hosts or environments

You can setup multiple hosts or environments by using the `host()` function multiple times.
If you do not specify **[selectors](https://deployer.org/docs/7.x/selector)** when running your deployer commands, you will be asked to choose which hosts to run that command for.

If you want to set common variables for all hosts, use the provided method chain factory method _(sic!)_, to call any number of methods on all previously defined hosts.

> Make sure to use labels to differentiate between environments.

```php
host('stage')
    ->set('deploy_path', '/usr/www/users/{{remote_user}}/stage')
    ->setLabels(['env' => 'stage']);

host('production')
    ->set('deploy_path', '/usr/www/users/{{remote_user}}/live')
    ->setLabels(['env' => 'prod']);

// use the method chain factory
onAllHosts()
    ->setHostname('www.example.org')
    ->setPort(22)
    ->setRemoteUser('www_data')
    ->set('http_user', 'www_data')
    ->set('public_dir', 'public')
    ->set('bin/php', 'php82')
    ->set('release_name', fn() => date('y-m-d_H-i-s'))
;
```

Alternatively, you can loop through all hosts.

```php
foreach (getHosts() as $host) {
    $host
        ->setHostname('www.example.org')
        ->setPort(22)
        ->setRemoteUser('www_data')
        ->set('http_user', 'www_data')
        ->set('public_dir', 'public')
        ->set('bin/php', 'php82')
        ->set('release_name', fn() => date('y-m-d_H-i-s'))
    ;
}
```

`getHosts()` is a shorthand for `Deployer::get()->hosts`.

> _Note: This documentation might change in the future as there might be a better way to achieve this. Keep yourself posted._

### Work in Progress

These templates are still work in progress and not yet fully implemented. Use with caution or not at all. 

```php
/** WIP: Deploy an htaccess file which will be renamed to .htaccess */
set('htaccess_filename', '.htaccess.prod');
after('deploy:shared', 'deploy:htaccess');
```
