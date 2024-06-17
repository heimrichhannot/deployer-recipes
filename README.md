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
    ->set('public_url', 'https://www.example.org')
    ->set('http_user', 'www_data')
    ->set('public_dir', 'public')
    ->set('deploy_path', '/usr/www/users/{{remote_user}}/docroot')
    ->set('bin/php', 'php82')
    ->set('bin/composer', 'composer')
    ->set('release_name', fn() => date('y-m-d_H-i-s'))
    /** In case ACL is unavailable, use chmod instead */
    // ->set('writable_mode', 'chmod')
;
```
```php
/** @example Add project-specific files */
// add('project_files', [
//     'config/routes.yaml',
//     'translations',
// ]);

/** @example Remove values from any variable */
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

/** @example Add yarn build task before deploying */
// before('deploy', 'ddev:yp');

/** @example Ask confirmation before going live */
// before('deploy', 'ask_confirm_prod');

/** @example Deploy `files/themes`, which are shared and not updated by default */
// after('deploy:shared', 'deploy:themes');
```

## Setup multiple hosts or environments

You may set up multiple hosts or environments by using the `host()` function multiple times.
If you do not specify **[selectors](https://deployer.org/docs/7.x/selector)** (like labels) when running your deployer commands, you will be asked to choose which hosts to run that command for.

If you want to set common variables for all hosts, use the provided method chain factory method _(sic!)_, to call any number of methods on all previously defined hosts.

> [!IMPORTANT]
> Make sure to use **[labels](https://deployer.org/docs/7.x/selector)** to differentiate between environments when defining multiple hosts.

```php
host('stage')
    ->set('public_url', 'https://stage.example.org')
    ->setLabels(['env' => 'stage'])
;

host('production')
    ->set('public_url', 'https://www.example.org')
    ->setLabels(['env' => 'prod'])
;

// use the method chain factory
onAllHosts()
    ->setHostname('www.example.org')
    ->setPort(22)
    ->setRemoteUser('www_data')
    ->set('http_user', 'www_data')
    ->set('public_dir', 'public')
    ->set('deploy_path', '/usr/www/users/{{remote_user}}/docroot/{{alias}}')
    ->set('bin/php', 'php82')
    ->set('bin/composer', 'composer')
    ->set('release_name', fn() => date('y-m-d_H-i-s'))
;
```

Alternatively, you may iterate over all hosts:

```php
foreach (getHosts() as $host) {
    $host
        ->setHostname('www.example.org')
        ->setPort(22)
        ->setRemoteUser('www_data')
        ->set('http_user', 'www_data')
        ->set('public_dir', 'public')
        ->set('deploy_path', '/usr/www/users/{{remote_user}}/docroot/{{alias}}')
        ->set('bin/php', 'php82')
        ->set('bin/composer', 'composer')
        ->set('release_name', fn() => date('y-m-d_H-i-s'))
    ;
}
```

`getHosts()` is a shorthand for `Deployer::get()->hosts`.

> [!NOTE]
> This documentation might change in the future as there might be a better way to achieve this. Keep yourself posted.

### The alias placeholder

Depending on your setup, you may want to automatically place different environments on the same host in respective directories.
You can use the `{{alias}}` placeholder to differentiate between the hosts' aliases as environment names.

For example:
```php
host('stage') // <- this is the alias
    ->setRemoteUser('www_data')
    ->set('deploy_path', '/usr/www/users/{{remote_user}}/docroot/{{alias}}')
; // will be evaluated to /usr/www/users/www_data/docroot/stage
```

## Utility Commands

### Clear the cache on the remote server

```bash
dep cache:clear
```

### Clear opcache

```bash
dep opcache:clear
```

### Deploy assets only (encore build folder)

```bash
dep deploy:assets
```

### Database actions

| Action                         | Command                                     |
|--------------------------------|---------------------------------------------|
| Clone remote database to local | <pre lang="bash">dep db:pull</pre>          |
| Push local database to remote  | <pre lang="bash">dep db:push</pre>          |
| Export remote database         | <pre lang="bash">dep db:export:remote</pre> |
| Export local database          | <pre lang="bash">dep db:export:local</pre>  |
| Import database on remote      | <pre lang="bash">dep db:import:remote</pre> |
| Import database locally        | <pre lang="bash">dep db:import:local</pre>  |

You may alternatively use its alias `dep db:clone` for `dep db:pull`.

#### What to do when `mysql` or `mysqldump` is unavailable

You can change the pull and push commands to use the `contao:backup` commands instead of `mysql` and `mysqldump`:

```php
set('db_dump_mode', 'contao');
```

> [!NOTE]
> This will only work if your local and remote databases are compatible.

## Work in Progress

These templates are still work in progress and not yet fully implemented. Use with caution or not at all. 

```php
/** WIP: Deploy an htaccess file which will be renamed to .htaccess */
set('htaccess_filename', '.htaccess.prod');
after('deploy:shared', 'deploy:htaccess');
```
