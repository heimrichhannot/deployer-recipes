<?php

namespace Deployer;

set('create_db_backup', true);
set('keep_releases', 10);

set('yaml_ext', get('yaml_ext', '.yaml'));

set('project_files', [
    'config/config{{yaml_ext}}',
    'contao',
    'files/themes',
    'src',
    'templates',
    'composer.json',
    'composer.lock',
]);

remove('shared_dirs', 'system/config');

add('shared_dirs', [
    'assets/images',
    'contao-manager',
    'files',
    'isotope',
    '{{public_path}}/share',
    'var/backups',
    'var/logs',
]);

remove('shared_files', 'config/parameters.yml');

add('shared_files', [
    'config/parameters{{yaml_ext}}',
    'public/.htaccess',
    'system/config/localconfig.php',
    '.env',
]);
