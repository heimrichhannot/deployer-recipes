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

    $path = '{{current_path}}/{{public_dir}}';
    if (!test('[ -d '.$path.'/build ]')) {
        throw new \Exception(parse('The path '.$path.'/build directory does not exist'));
    }

    info('Updating encore assets files');

    if (test('[ -d '.$path.'/build_new ]')) {
        run('rm -rf '.$path.'/build_new');
    }

    upload('{{public_dir}}/build/', $path.'/build_new', ['options' => ['--recursive']]);

    if (test('[ -d '.$path.'/build_old ]')) {
        run('rm -rf '.$path.'/build_old');
    }

    run('mv '.$path.'/build '.$path.'/build_old');
    run('mv '.$path.'/build_new '.$path.'/build');
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
