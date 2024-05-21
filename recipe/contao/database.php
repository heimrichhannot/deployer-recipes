<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;

desc('Clone database from remote.');
task('db:pull', static function () {
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
    runLocally('{{local_cmd_db_restore}}', ['timeout' => null]);
    info('Database cloned successfully');
})->once();

desc('Alias for db:pull');
task('db:clone', ['db:pull']);

desc('Push the local database to remote.');
task('db:push', static function () {
    if (askConfirmation('Create a new database backup on localhost?', true))
    {
        // create a backup
        info('Creating database backup on localhost');
        runLocally('{{local_cmd_db_dump}}');
    }
    elseif (!askConfirmation('Use localhost\'s latest existing database backup?', true))
    {
        info('Nothing to do. Bye!');
        return;
    }

    if (askConfirmation('Backup database on remote?', true))
    {
        // create a backup
        info('Creating database backup on remote');
        run('{{bin/console}} contao:backup:create {{console_options}}');
    }

    // get list of backups
    info('Fetching latest database backup');
    $json = runLocally('{{local_cmd_db_list}}');

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

    // uploading backup
    info("Uploading database backup: $filename");
    upload("var/backups/$filename", '{{current_path}}/var/backups/');
    info('Database backup uploaded successfully');

    if (!askConfirmation('Import local database dump into remote database?', true)) {
        return;
    }
    $cmdDBRestore = get('local_cmd_db_restore');
    if (!$cmdDBRestore) {
        throw new ConfigurationException('local_cmd_db_restore is not set');
    }
    run("{{bin/console}} contao:backup:restore $filename {{console_options}}", ['timeout' => null]);
    info('Database pushed successfully');
})->once();
