<?php

namespace Deployer;

import('recipe/contao.php');

set('project_files', [
    'config/config.yaml',
    'contao',
    'files/themes',
    'src',
    'templates',
    'composer.json',
    'composer.lock',
]);

set('composer_options', '--no-dev');
set('create_db_backup', true);

desc('Prepares a new release');
task('deploy:prepare', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:assets:release',
    'deploy:shared',
    'deploy:writable',
]);

desc('Upload project files');
task('deploy:update_code', function () {
    foreach(get('project_files') as $src) {
        upload($src, '{{release_path}}/', ['options' => ['--recursive', '--relative']]);
    }
});

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

desc('Upload encore assets files');
task('deploy:assets:release', function () {
    upload('{{public_dir}}/build', '{{release_path}}/', ['options' => ['--recursive', '--relative']]);
});

desc('Update encore assets files');
task('deploy:assets', function () {
    $tmpDir = '{{deploy_path}}/tmp/'.uniqid('deploy_assets_', true);
    run('mkdir -p '.$tmpDir);
    upload('{{public_dir}}/build/', $tmpDir, ['options' => ['--recursive', '--relative']]);
    run('[ -d {{deploy_path}}/current/public/build_old ] && rm -rf {{deploy_path}}/current/public/build_old');
    run('[ -d {{deploy_path}}/current/public/build ] && mv -f {{deploy_path}}/current/public/build {{deploy_path}}/current/public/build_old');
    run("mv $tmpDir/public/build {{deploy_path}}/current/public/build");
    run('rm -rf '.$tmpDir);
});

desc('Upload theme files');
task('deploy:themes', function () {
    upload('files/themes/', '{{deploy_path}}/shared/', ['options' => ['--recursive', '--relative']]);
});
after('deploy:shared', 'deploy:themes');

desc('Run Contao migrations');
task('contao:migrate', function () {
    if (get('create_db_backup') === true) {
        run('{{bin/console}} contao:backup:create {{console_options}}');
    }
    run('{{bin/console}} contao:migrate --no-backup {{console_options}}');
});

set('keep_releases', 10);

after('deploy:failed', 'deploy:unlock');
