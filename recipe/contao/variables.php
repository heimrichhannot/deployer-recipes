<?php

namespace Deployer;

set('create_db_backup', true);
set('keep_releases', 10);

set('project_files', [
    'config/config.yaml',
    'contao',
    'files/themes',
    'src',
    'templates',
    'composer.json',
    'composer.lock',
]);

(function () {
    $sharedDirs = get('shared_dirs');
    unset($sharedDirs[array_search('system/config', $sharedDirs)]);
    set('shared_dirs', $sharedDirs);
})();

add('shared_dirs', [
    'assets/images',
    'contao-manager',
    'files',
    'isotope',
    '{{public_path}}/share',
    'var/backups',
    'var/logs',
]);

add('shared_files', [
    'public/.htaccess',
    'system/config/localconfig.php',
    '.env',
]);
