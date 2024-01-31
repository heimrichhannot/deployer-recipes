<?php

namespace Deployer;

set('create_db_backup', true);
set('keep_releases', 10);

$yamlExt = get('yaml_ext', '.yml');

set('project_files', [
    'config/config' . $yamlExt,
    'contao',
    'files/themes',
    'src',
    'templates',
    'composer.json',
    'composer.lock',
]);

yank('shared_dirs', 'system/config');

add('shared_dirs', [
    'assets/images',
    'contao-manager',
    'files',
    'isotope',
    '{{public_path}}/share',
    'var/backups',
    'var/logs',
]);

yank('shared_files', 'config/parameters.yml');

add('shared_files', [
    'config/parameters' . $yamlExt,
    'public/.htaccess',
    'system/config/localconfig.php',
    '.env',
]);
