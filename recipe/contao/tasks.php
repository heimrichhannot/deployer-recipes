<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;
use Symfony\Component\Console\Output\OutputInterface;

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
    $publicPath = '{{current_path}}/{{public_dir}}';
    $buildPath = $publicPath . '/build';
    $newBuildPath = $publicPath . '/build_new';
    $oldBuildPath = $publicPath . '/build_old';

    if (!test("[ -d $buildPath ]")) {
        run("mkdir -p " . $buildPath);
        if (!test("[ -d $buildPath ]")) {
            throw new \Exception(parse("The path \"$buildPath\" does not exist and could not be created."));
        }
    }

    info('Updating encore assets files');

    if (test("[ -d $newBuildPath ]")) {
        run("rm -rf $newBuildPath");
    }

    upload('{{public_dir}}/build/', $newBuildPath, ['options' => ['--recursive']]);

    if (test("[ -d $oldBuildPath ]")) {
        run('rm -rf ' . $oldBuildPath);
    }

    run("mv $buildPath $oldBuildPath");
    run("mv $newBuildPath $buildPath");
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

desc('Clear cache on remote server');
task('cache:clear', function () {
    writeln(run('{{bin/console}} cache:clear {{console_options}}'));
});

desc('Clear opt cache on remote server');
task('cache:opcache:clear', function () {
    if (!has('public_url')) {
        warning('No public_url defined. Skipping opcache clear.');
        return;
    }

    writeln('Create tmp cache clear file', OutputInterface::VERBOSITY_VERBOSE);

    $path = parse('{{deploy_path}}/current/{{public_dir}}');
    $tmpFileName = uniqid('optcache-clear-') . '.php';

    run("cd $path && echo \"<?php if (function_exists('opcache_reset')) {echo opcache_reset();} else {echo '2';}\" > $tmpFileName");
    writeln('Dumped file to '.$path.'/'.$tmpFileName, OutputInterface::VERBOSITY_VERBOSE);

    writeln('Execute cache clear file', OutputInterface::VERBOSITY_VERBOSE);
    $url = rtrim(parse('{{public_url}}'), '/').'/'.$tmpFileName;
    $result = run("cd $path && curl -kL -A \"deployer/clear_opt_cache\" $url");

    writeln('Remove tmp cache clear file', OutputInterface::VERBOSITY_VERBOSE);
    run("cd $path && rm $tmpFileName");

    if (2 === (int)$result) {
        warning('Opcache is not available on the server.');
        return;
    }

    if (1 !== (int)$result) {
        warning('Failed to clear opcache.');
        return;
    }

    info('Opcache cleared!');
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
