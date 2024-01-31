<?php

namespace Deployer;

desc('Upload project files');
task('deploy:project_files', function () {
    foreach (get('project_files') as $src)
    {
        upload($src, '{{release_path}}/', ['options' => ['--recursive', '--relative']]);
    }
});

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

desc('Run Contao migrations');
task('contao:migrate', function () {
    if (get('create_db_backup') === true) {
        run('{{bin/console}} contao:backup:create {{console_options}}');
    }
    run('{{bin/console}} contao:migrate --no-backup {{console_options}}');
});

after('deploy:failed', 'deploy:unlock');
