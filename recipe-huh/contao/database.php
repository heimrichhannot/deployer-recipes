<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;

set('db_dump_mode', 'mysql');
set('bin/mysql', 'mysql');
set('bin/mysqldump', 'mysqldump');

desc('Clone database from remote to local.');
task('db:pull', static function () {
    switch (get('db_dump_mode'))
    {
        case 'mysql':
            invoke('db:pull:mysql');
            break;
        case 'contao':
            invoke('db:pull:contao');
            break;
        default:
            throw new ConfigurationException('Invalid db_dump_mode');
    }
})->once();

desc('Alias for db:pull');
task('db:clone', ['db:pull']);

desc('Clone database from remote with contao:backup commands.');
task('db:pull:contao', static function () {
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

    if (askConfirmation('Delete database backup file on remote?', false)) {
        run("rm {{current_path}}/var/backups/$filename");
    }

    if (askConfirmation('Delete cloned database dump file locally?', false)) {
        runLocally("rm var/backups/$filename");
    }
})->once();

function extractDatabaseFromIni(string $filepath): ?array
{
    $env = \parse_ini_file($filepath);

    $regex = '/mysql:\/\/(?P<user>[^:]+)(?::(?P<pass>[^@]+))?@(?P<host>[^:]+):(?P<port>[^\/]+)\/(?P<db>.+)/';
    $url = $env['DATABASE_URL'] ?? null;

    if (!$url) {
        return null;
    }

    \preg_match($regex, $url, $matches);

    return $matches;
}

function databaseParamsToCliString(array $matches): ?string
{
    $dbUser = $matches['user'] ?? null;
    $dbPass = \urldecode($matches['pass'] ?? '');
    $dbHost = $matches['host'] ?? null;
    $dbPort = $matches['port'] ?? '3306';
    $dbName = $matches['db'] ?? null;
    $pass = $dbPass ? ('-p' . \escapeshellarg($dbPass)) : '--password=""';

    if (!$dbUser || !$dbHost || !$dbName) {
        return null;
    }

    return "$pass -u $dbUser -h $dbHost -P $dbPort $dbName";
}

desc('Clone database from remote with mysqldump and mysql.');
task('db:pull:mysql', static function () {
    info('Fetching remote database credentials');
    runLocally('mkdir -p var/tmp');
    $absPath = run('readlink -f {{current_path}}/.env.local');
    download($absPath, 'var/tmp/.env.remote');

    $matches = extractDatabaseFromIni('var/tmp/.env.remote');

    if ($matches === null) {
        throw new \RuntimeException('No remote database credentials found in .env.local of remote host');
    }

    $conn = databaseParamsToCliString($matches);
    $dbName = $matches['db'];

    \unlink('var/tmp/.env.remote');

    // create a backup
    info('Creating database backup on remote');
    $now = \date('Y-m-d_H-i-s_');
    $filename = "$now$dbName.sql";

    run("mkdir -p {{current_path}}/var/backups");
    run("{{bin/mysqldump}} --add-drop-table $conn > {{current_path}}/var/backups/$filename");

    // download backup
    info("Downloading database backup: $filename");
    runLocally('mkdir -p var/backups');
    download("{{current_path}}/var/backups/$filename", 'var/backups/');
    info('Database backup downloaded successfully');

    if (!askConfirmation('Clone remote to local database?', true)) {
        return;
    }

    $matches = extractDatabaseFromIni('.env.local');
    if (empty($matches)) {
        $matches = extractDatabaseFromIni('.env');
    }
    if (empty($matches)) {
        throw new \RuntimeException('No local database credentials found in local .env.local or .env');
    }
    $localConn = databaseParamsToCliString($matches);
    runLocally("mysql $localConn < var/backups/$filename", ['timeout' => null]);
    info('Database cloned successfully');

    if (askConfirmation('Delete database backup file on remote?', false)) {
        run("rm {{current_path}}/var/backups/$filename");
    }

    if (askConfirmation('Delete cloned database dump file locally?', false)) {
        runLocally("rm var/backups/$filename");
    }
})->once();

desc('Push the local database to remote.');
task('db:push', static function () {
    switch (get('db_dump_mode'))
    {
        case 'mysql':
            invoke('db:push:mysql');
            break;
        case 'contao':
            invoke('db:push:contao');
            break;
        default:
            throw new ConfigurationException('Invalid db_dump_mode');
    }
})->once();

task('db:push:contao', static function () {
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

    set('db_dump_filename', $filename);

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

    if (askConfirmation('Delete database backup file on remote?', false)) {
        run("rm {{current_path}}/var/backups/$filename");
    }

    if (askConfirmation('Delete pushed database backup file locally?', false)) {
        runLocally("rm var/backups/$filename");
    }
})->once();

desc('Push the local database to remote with mysqldump and mysql.');
task('db:push:mysql', static function () {
    $matches = extractDatabaseFromIni('.env.local');
    if (empty($matches)) {
        $matches = extractDatabaseFromIni('.env');
    }
    if (empty($matches)) {
        throw new \RuntimeException('No database credentials found in .env.local or .env');
    }
    $localConn = databaseParamsToCliString($matches);
    $dbName = $matches['db'];

    // create a backup
    info('Creating database backup of local database');
    $now = \date('Y-m-d_H-i-s_');
    $filename = "$now$dbName.sql";
    runLocally('mkdir -p var/backups');
    runLocally("{{bin/mysqldump}} --add-drop-table $localConn > var/backups/$filename");
    info("Database backup created successfully: $filename");

    info('Fetching remote database credentials');
    runLocally('mkdir -p var/tmp');
    $absPath = run('readlink -f {{current_path}}/.env.local');
    download($absPath, 'var/tmp/.env.remote');

    $matches = extractDatabaseFromIni('var/tmp/.env.remote');
    $remoteConn = databaseParamsToCliString($matches);
    $dbName = $matches['db'];

    \unlink('var/tmp/.env.remote');

    if (askConfirmation('Backup database on remote?', true))
    {
        // create a backup
        $now = \date('Y-m-d_H-i-s_');
        $remoteBackupFilename = "backup_$now$dbName.sql";
        info('Creating database backup on remote');
        run("mkdir -p {{current_path}}/var/backups");
        run("{{bin/mysqldump}} --add-drop-table $remoteConn > {{current_path}}/var/backups/$remoteBackupFilename");
        info("Database backup created successfully: $remoteBackupFilename");
    }

    info("Uploading database dump: $filename");
    upload("var/backups/$filename", '{{current_path}}/var/backups/');
    info('Database backup uploaded successfully');

    if (askConfirmation('Import local database dump into remote database?', true)) {
        run("mysql $remoteConn < {{current_path}}/var/backups/$filename", ['timeout' => null]);
        info('Database pushed successfully');
    }

    if (askConfirmation('Delete database backup file on remote?', false)) {
        run("rm {{current_path}}/var/backups/$filename");
    }

    if (askConfirmation('Delete pushed database backup file locally?', false)) {
        runLocally("rm var/backups/$filename");
    }
})->once();

desc('Import a local database backup with mysql');
task('db:import:local', static function () {
    $matches = extractDatabaseFromIni('.env.local');
    if (empty($matches)) {
        $matches = extractDatabaseFromIni('.env');
    }
    if (empty($matches)) {
        throw new \RuntimeException('No database credentials found in .env.local or .env');
    }
    $localConn = databaseParamsToCliString($matches);

    $filenames = \scandir('var/backups');
    $filenames = \array_filter($filenames, static function ($filename) {
        return \preg_match('/\.sql$/', $filename);
    });
    $filename = askChoice('Choose a backup to import', $filenames);
    if (!\file_exists("var/backups/$filename")) {
        throw new \RuntimeException("File not found: var/backups/$filename");
    }

    runLocally("mysql $localConn < var/backups/$filename", ['timeout' => null]);
    info('Database imported successfully');
})->once();

desc('Import a remote database backup with mysql');
task('db:import:remote', static function () {
    info('Fetching remote database credentials');
    runLocally('mkdir -p var/tmp');
    $absPath = run('readlink -f {{current_path}}/.env.local');
    download($absPath, 'var/tmp/.env.remote');

    $matches = extractDatabaseFromIni('var/tmp/.env.remote');
    $conn = databaseParamsToCliString($matches);
    $dbName = $matches['db'];

    \unlink('var/tmp/.env.remote');

    $filenames = run("find {{current_path}}/var/backups/ -name \*.sql -printf '%f\n'");
    $filenames = \explode("\n", $filenames);
    \sort($filenames, \SORT_NATURAL);

    $filename = askChoice('Choose a backup to import', $filenames);
    if (!test("test -f {{current_path}}/var/backups/$filename")) {
        throw new \RuntimeException("File not found: var/backups/$filename");
    }

    run("mysql $conn < {{current_path}}/var/backups/$filename", ['timeout' => null]);
    info('Database imported successfully');
})->once();

desc('Export the local database with mysqldump');
task('db:export:local', static function () {
    $matches = extractDatabaseFromIni('.env.local');
    if (empty($matches)) {
        $matches = extractDatabaseFromIni('.env');
    }
    if (empty($matches)) {
        throw new \RuntimeException('No database credentials found in .env.local or .env');
    }
    $localConn = databaseParamsToCliString($matches);
    $dbName = $matches['db'];

    $now = \date('Y-m-d_H-i-s_');
    $filename = "$now$dbName.sql";
    runLocally("{{bin/mysqldump}} --add-drop-table $localConn > var/backups/$filename");
    info("Database exported successfully: $filename");
})->once();

desc('Export the remote database with mysqldump');
task('db:export:remote', static function () {
    info('Fetching remote database credentials');
    runLocally('mkdir -p var/tmp');
    $absPath = run('readlink -f {{current_path}}/.env.local');
    download($absPath, 'var/tmp/.env.remote');

    $matches = extractDatabaseFromIni('var/tmp/.env.remote');
    $conn = databaseParamsToCliString($matches);
    $dbName = $matches['db'];

    \unlink('var/tmp/.env.remote');

    $now = \date('Y-m-d_H-i-s_');
    $filename = "$now$dbName.sql";
    run("{{bin/mysqldump}} --add-drop-table $conn > {{current_path}}/var/backups/$filename");
    info("Database exported successfully: $filename");

    if (askConfirmation('Download the exported database dump?', true)) {
        download("{{current_path}}/var/backups/$filename", 'var/backups/');
        info('Database dump downloaded successfully');

        if (askConfirmation('Delete the exported database dump on remote?', false)) {
            run("rm {{current_path}}/var/backups/$filename");
        }
    }
})->once();
