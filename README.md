# deployer-recipes
Deployer recipes used at [Heimrich & Hannot GmbH](https://www.heimrich-hannot.de).

## Install

```shell
composer require --dev heimrichhannot/deployer-recipes
```

## Usage with Contao 4.13+

Create a `deploy.php` file in your Contao project root directory
and customize the following content according to your needs.
```php
<?php # deploy.php

date_default_timezone_set('Europe/Berlin');

namespace Deployer;

import(__DIR__ . '/vendor/heimrichhannot/deployer-recipes/recipe/contao.php');

set('rsync_src', __DIR__);

host('www.example.org')
    ->setPort(22)
    ->set('remote_user', 'www_data')
    ->set('http_user', 'www_data')
    ->set('deploy_path', '/usr/www/users/{{remote_user}}/docroot')
    ->set('bin/php', 'php82')
    ->set('public_dir', 'public')
    ->set('bin/composer', '{{bin/php}} {{deploy_path}}/composer.phar')
    ->set('release_name', fn() => date('y-m-d_H-i-s'))
    // In case no ACL is available, use chmod instead
    // ->set('writable_mode', 'chmod')
;

// Optional: Add project-specific files to deploy
// add('project_files', [
//     'config/routes.yaml',
//     'translations',
// ]);

// Optional: Add project-specific files to exclude from deploy
// add('exclude', [
//     '.githooks',
// ]);

// Optional: Add yarn build task
// import(__DIR__ . '/vendor/heimrichhannot/deployer-recipes/recipe/contao/ddev.php');
// before('deploy', 'ddev:yarn:prod');
```
