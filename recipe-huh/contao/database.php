<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;

set('db_dump_mode', 'mysql');
set('bin/mysql', 'mysql');
set('bin/mysqldump', 'mysqldump');
set('db_pull_excluded_tables', [
    'tl_crawl_queue',
    'tl_log',
    'tl_md_outbox_recipient',
    'tl_md_outbox_recipient_data',
    'tl_md_recipient',
    'tl_nc_queue',
    'tl_search',
    'tl_search_index',
    'tl_search_term',
    'tl_undo',
    'tl_version',
]);

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

function fetchLocalDatabase(): string
{
    $matches = extractDatabaseFromEnv('.env.local');
    if (empty($matches)) {
        $matches = extractDatabaseFromEnv('.env');
    }
    if (empty($matches)) {
        throw new \RuntimeException('No local database credentials found in local .env.local or .env');
    }
    $connection = databaseParamsToCliString($matches);
    if (null === $connection) {
        throw new \RuntimeException('No local database credentials found in local.env.local or .env');
    }
    return $connection;
}

function extractDatabaseFromEnv(string $filepath): ?array
{
    $regex = '/^mysql:\/\/(?P<user>[^:\/@]+)(?::(?P<pass>[^@]*))?@(?P<host>[^:\/]+)(?::(?P<port>[^\/]+))?\/(?P<db>.+)$/';
//    $regex = '/mysql:\/\/(?P<user>[^:]+)(?::(?P<pass>[^@]+))?@(?P<host>[^:]+):(?P<port>[^\/]+)\/(?P<db>.+)/';
    $url = extractEnvValue($filepath, 'DATABASE_URL');

    if (!$url) {
        return null;
    }

    \preg_match($regex, $url, $matches);

    return $matches;
}

function extractDatabaseFromIni(string $filepath): ?array
{
    return extractDatabaseFromEnv($filepath);
}

function extractEnvValue(string $filepath, string $name): ?string
{
    if (!\is_file($filepath)) {
        return null;
    }

    $lines = \file($filepath, \FILE_IGNORE_NEW_LINES);

    if (false === $lines) {
        return null;
    }

    foreach ($lines as $line) {
        if (!\preg_match('/^\s*(?:export\s+)?' . \preg_quote($name, '/') . '\s*=\s*(.*)\s*$/', $line, $matches)) {
            continue;
        }

        $value = \trim($matches[1]);
        $quote = $value[0] ?? null;

        if (($quote === '"' || $quote === "'") && \str_ends_with($value, $quote)) {
            $value = \substr($value, 1, -1);
        }

        return $value;
    }

    return null;
}

function databaseParamsToCliString(array $matches): ?string
{
    $dbUser = $matches['user'] ?? null;
    $dbPass = \urldecode($matches['pass'] ?? '');
    $dbHost = $matches['host'] ?? null;
    $dbPort = $matches['port'] ?: '3306';
    $dbName = $matches['db'] ?? null;
    $pass = $dbPass ? ('-p' . \escapeshellarg($dbPass)) : '--password=""';

    if (!$dbUser || !$dbHost || !$dbName) {
        return null;
    }

    return "$pass -u $dbUser -h $dbHost -P $dbPort $dbName";
}

function buildMysqlIgnoreTableOptions(array $tables, string $connection, string $database): string
{
    $tables = \array_values(\array_unique(\array_filter(\array_map(static function ($table): string {
        return (string) $table;
    }, $tables))));

    if (empty($tables)) {
        return '';
    }

    $sqlTableNames = \implode(',', \array_map(static function (string $table): string {
        return mysqlQuoteString($table);
    }, $tables));
    $sql = \sprintf(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s)',
        mysqlQuoteString($database),
        $sqlTableNames
    );
    $existingTables = run("{{bin/mysql}} --batch --skip-column-names $connection --execute=" . \escapeshellarg($sql));
    $existingTables = \array_values(\array_filter(\array_map('trim', \explode("\n", $existingTables))));

    if (empty($existingTables)) {
        return '';
    }

    $existingTables = \array_flip($existingTables);
    $tables = \array_values(\array_filter($tables, static function (string $table) use ($existingTables): bool {
        return isset($existingTables[$table]);
    }));

    $options = \array_map(static function (string $table) use ($database): string {
        return \escapeshellarg("--ignore-table=$database.$table");
    }, $tables);

    return \implode(' ', $options);
}

function mysqlQuoteString(string $value): string
{
    return "'" . \str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
}

desc('Clone database from remote with mysqldump and mysql.');
task('db:pull:mysql', static function () {
    info('Fetching remote database credentials');
    runLocally('mkdir -p var/tmp');
    $absPath = run('readlink -f {{current_path}}/.env.local');
    download($absPath, 'var/tmp/.env.remote');

    $matches = extractDatabaseFromEnv('var/tmp/.env.remote');

    if ($matches === null) {
        throw new \RuntimeException('No remote database credentials found in .env.local of remote host');
    }

    $conn = databaseParamsToCliString($matches);
    $dbName = $matches['db'];

    info('Fetching local database credentials');
    $localConn = fetchLocalDatabase();

    \unlink('var/tmp/.env.remote');

    // create a backup
    info('Creating database backup on remote');
    $now = \date('Y-m-d_H-i-s_');
    $filename = "$now$dbName.sql";

    run("mkdir -p {{current_path}}/var/backups");
    $excludedTables = get('db_pull_excluded_tables');

    if (!\is_array($excludedTables)) {
        throw new ConfigurationException('db_pull_excluded_tables must be an array');
    }

    $dumpOptions = \trim('--add-drop-table ' . buildMysqlIgnoreTableOptions($excludedTables, $conn, $dbName));
    run("{{bin/mysqldump}} $dumpOptions $conn > {{current_path}}/var/backups/$filename");

    // download backup
    info("Downloading database backup: $filename");
    runLocally('mkdir -p var/backups');
    download("{{current_path}}/var/backups/$filename", 'var/backups/');
    info('Database backup downloaded successfully');

    if (!askConfirmation('Clone remote to local database?', true)) {
        return;
    }

    runLocally("mysql $localConn < var/backups/$filename", ['timeout' => null]);
    info('Database cloned successfully');

    if (askConfirmation('Delete database backup file on remote?', false)) {
        run("rm {{current_path}}/var/backups/$filename");
    }

    if (askConfirmation('Delete cloned database dump file locally?', false)) {
        runLocally("rm var/backups/$filename");
    }

    if (askConfirmation('Run local database migrations now?', true)) {
        runLocally('{{local/bin/contao-console}} contao:migrate --no-backup {{console_options}}', ['timeout' => null]);
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
    $matches = extractDatabaseFromEnv('.env.local');
    if (empty($matches)) {
        $matches = extractDatabaseFromEnv('.env');
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

    $matches = extractDatabaseFromEnv('var/tmp/.env.remote');
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
    $matches = extractDatabaseFromEnv('.env.local');
    if (empty($matches)) {
        $matches = extractDatabaseFromEnv('.env');
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

    $matches = extractDatabaseFromEnv('var/tmp/.env.remote');
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
    $matches = extractDatabaseFromEnv('.env.local');
    if (empty($matches)) {
        $matches = extractDatabaseFromEnv('.env');
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

    $matches = extractDatabaseFromEnv('var/tmp/.env.remote');
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
