<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;

desc('Upload project files');
task('deploy:project_files', function () {
    $exclude = get('exclude', []);
    foreach (get('project_files') as $src)
    {
        if (in_array($src, $exclude)) {
            continue;
        }
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
    if (get('ask_confirm_migrate') === true
        && !askConfirmation('Run database migrations now?', true))
    {
        return;
    }
    if (get('create_db_backup') === true) {
        run('{{bin/console}} contao:backup:create {{console_options}}');
    }
    run('{{bin/console}} contao:migrate --no-backup {{console_options}}');
});

desc('Copy .htaccess files');
task('deploy:htaccess', function () {
    $htaccess = get('htaccess_filename');
    $htpasswd = get('htpasswd_filename');
    if ($htaccess && $htaccess !== '.htaccess')
    {
        cd('{{release_path}}/{{public_path}}');
        run("if [ -f \"./$htaccess\" ]; then mv ./$htaccess ./.htaccess; fi");
        run("rm -f $htaccess .htaccess.* .htaccess_*");
    }
    if ($htpasswd && $htpasswd !== '.htpasswd')
    {
        cd('{{release_path}}/{{public_path}}');
        run("if [ -f \"./$htpasswd\" ]; then mv ./$htpasswd ./.htpasswd; fi");
        run("rm -f $htpasswd .htpasswd.* .htpasswd_*");
    }
});

desc('Downloads the files from remote.');
task('files:retrieve', static function () {
    if (!askConfirmation('Download remote files without deletes?')) {
        return;
    }
    download("{{release_or_current_path}}/files/", 'files/');
    info('Download of files/ directory completed');
});

desc('Clone database from remote.');
task('db:clone', static function () {
    if (askConfirmation('Create a new database backup on remote?', true))
    {
        // create a backup
        info('Creating database backup on remote');
        run('{{bin/console}} contao:backup:create {{console_options}}');
    }
    elseif (!askConfirmation('Use remote\'s latest existing database backup?', true))
    {
        info('Nothing to do. Bye!');
        return;
    }

    // get list of backups
    info('Fetching latest database backup');
    $json = run('{{bin/console}} contao:backup:list --format=json {{console_options}}');

    // get latest backup
    $backups = \json_decode($json, true);
    \usort($backups, static function ($a, $b) {
        return \strtotime($a['createdAt']) <=> \strtotime($b['createdAt']);
    });
    $backup = end($backups) ?? null;
    $filename = $backup['name'] ?? null;

    if (!$filename) {
        throw new \RuntimeException('No backup found');
    }

    set('db_dump_filename', $filename);

    // download backup
    info("Downloading database backup: $filename");
    download("{{current_path}}/var/backups/$filename", 'var/backups/');
    info('Database backup downloaded successfully');

    if (!askConfirmation('Clone remote database to local?', true)) {
        return;
    }
    $cmdDBRestore = get('local_cmd_db_restore');
    if (!$cmdDBRestore) {
        throw new ConfigurationException('local_cmd_db_restore is not set');
    }
    runLocally('{{local_cmd_db_restore}}');
    info('Database cloned successfully');
})->once();
