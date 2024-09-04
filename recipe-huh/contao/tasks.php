<?php

namespace Deployer;

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

desc('Clear opcache on remote server');
task('opcache:clear', function () {
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

desc('Alias for opcache:clear');
task('cache:opcache:clear', ['opcache:clear']);

desc('Reaload PHP FastCGI');
task('php-fcgi:reload', function () {
    if (get('reload_php_fcgi', false) === false) {
        info('PHP FastCGI reload is disabled. Set "reload_php_fcgi" to true to enable it.');
        return;
    }

    $res = run('sg users -c \'pgrep -U $(id -u) "^php[0-9]+\.fcgi$"\' | xargs --no-run-if-empty kill -USR1');
    if ($res) {
        writeln($res);
        warning('PHP FastCGI reload failed.');
    } else {
        info('PHP FastCGI reloaded');
    }
});

desc('Create predefined symlinks');
task('deploy:symlinks', static function () {
    $symlinks = get('symlinks');
    if (empty($symlinks)) {
        return;
    }

    foreach ($symlinks as $link => $target)
    {
        $link = ['{{release_or_current_path}}', ...\explode('/', $link)];
        $link = \implode('/', $link);
        $link = parse($link);

        if (test("[ -L $link ]")) {
            run("rm -rf $link");
        }

        if (test("[ -d $link ]")) {
            warning("The directory \"$link\" already exists and is not a symlink. It will be skipped.");
            continue;
        }

        if (test("[ -e $link ]")) {
            warning("The file \"$link\" already exists and is not a symlink. It will be skipped.");
            continue;
        }

        $target = parse($target);

        run("ln -ns $target $link");
        info("symlinked: $link -> $target");
    }
});
