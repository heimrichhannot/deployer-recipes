<?php

namespace Deployer;

desc('Deploy contao-manager');
task('deploy:contao-manager', function () {

    $sharedFile = "{{deploy_path}}/shared/{{public_dir}}/contao-manager.phar.php";
    $publicFile = '{{release_or_current_path}}/{{public_dir}}/contao-manager.phar.php';

    if (get('contao_manager_deploy', true) === false)
    {
        info("deployment of contao-manager.phar.php disabled, skipping...");
        return;
    }

    $filenames = \array_map('basename', get('shared_files', []));
    if (\in_array('contao-manager.phar.php', $filenames))
    {
        info("contao-manager.phar.php found in shared_files, skipping automated deployment");
        return;
    }

    // check if contao-manager.phar.php exists
    if (test("[ -f $sharedFile ]"))
    {
        // skip download
        info("contao-manager.phar.php already exists in shared, won't download it again");
        // self-update contao-manager.phar
        run("{{bin/php}} $sharedFile self-update");
        info("contao-manager.phar.php self-updated successfully");
    }
    else
    {
        $downloadUrl = get(
            'contao_manager_download_url',
            'https://download.contao.org/contao-manager/stable/contao-manager.phar'
        );

        // download contao-manager.phar
        info("downloading contao-manager.phar from $downloadUrl");
        run("curl -sS -o $sharedFile $downloadUrl");
        info("contao-manager.phar downloaded successfully");
    }

    // check if symlink exists
    if (test("[ -L $publicFile ]"))
    {
        // remove symlink
        run("rm $publicFile");
    }

    // if file exists and is not a symlink
    if (test("[ -f $publicFile ]"))
    {
        // skip deployment
        info("contao-manager.phar.php is a deployed file, won't deploy it again");
        return;
    }

    // create symlink
    run("ln -s $sharedFile $publicFile");

    info("contao-manager.phar.php deployed");
});
