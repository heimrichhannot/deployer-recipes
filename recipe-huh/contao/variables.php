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
    '{{public_path}}/.htaccess',
    'system/config/localconfig.php',
    '.env',
]);

set('bin/contao-console', '{{bin/php}} {{release_path}}/vendor/bin/contao-console');

set('local/bin/contao-console', function () {
    return runLocally('which contao-console');
});

set('tags_production', ['prod', 'production', 'live']);
