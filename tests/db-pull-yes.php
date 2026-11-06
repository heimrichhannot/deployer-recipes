<?php

// Run: php tests/db-pull-yes.php [path/to/deployer/deployer]
require ($argv[1] ?? dirname(__DIR__) . '/vendor/deployer/deployer') . '/vendor/autoload.php';

use Deployer\Deployer;
use Deployer\Host\Host;
use Deployer\Task\Context;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$deployer = new Deployer(new Application());
require dirname(__DIR__) . '/recipe-huh/contao/database.php';
$deployer['output'] = new BufferedOutput();
$deployer['messenger'] = new class {
    public function startTask($task): void {}
    public function endTask($task): void {}
};
$runner = new class {
    public array $commands = [];
    public function run($host, string $command, array $options): string
    {
        $this->commands[] = $command;
        if (str_contains($command, 'readlink')) {
            return '/remote/.env.local';
        }
        if (str_contains($command, 'contao:backup:list')) {
            return '[{"name":"backup.sql","createdAt":"2026-01-01"}]';
        }
        return '';
    }
};
$deployer['processRunner'] = $runner;
$deployer['sshClient'] = $runner;
$deployer['rsync'] = new class {
    public function call($host, string $source, string $destination, array $config): void
    {
        if ($destination === 'var/tmp/.env.remote') {
            file_put_contents($destination, 'DATABASE_URL="mysql://remote:password@localhost:3306/remote"');
        }
    }
};

$directory = sys_get_temp_dir() . '/db-pull-yes-' . bin2hex(random_bytes(6));
mkdir($directory . '/var/tmp', 0777, true);
$originalDirectory = getcwd();
chdir($directory);
file_put_contents('.env.local', 'DATABASE_URL="mysql://local:password@localhost:3306/local"');

try {
    foreach (['mysql', 'contao'] as $mode) {
        foreach ([['-y'], ['--yes'], ['-y', '-n'], ['-n'], []] as $flags) {
            $definition = clone $deployer->inputDefinition;
            $definition->addOptions($deployer->console->getDefinition()->getOptions());
            $input = new ArgvInput(array_merge(['dep'], $flags), $definition);
            $input->setInteractive(false);
            $deployer['input'] = $input;
            $runner->commands = [];
            $host = new Host('test');
            $host->set('db_dump_mode', $mode);
            $host->set('db_pull_excluded_tables', []);
            $host->set('current_path', '/remote');
            $host->set('bin/console', 'remote-console');
            $host->set('local/bin/contao-console', 'local-console');
            $host->set('console_options', '');
            $host->set('local_cmd_db_restore', 'local-console contao:backup:restore {{db_dump_filename}} {{console_options}}');
            $context = new Context($host);
            Context::push($context);
            try {
                // Exercise alias, dispatcher, and each directly invokable mode.
                foreach (['db:clone', 'db:pull', 'db:pull:' . $mode] as $task) {
                    $runner->commands = [];
                    $deployer->tasks->get($task)->run($context);
                    $commands = implode("\n", $runner->commands);
                    $yes = in_array('-y', $flags, true) || in_array('--yes', $flags, true);
                    $label = $task . ' ' . implode(' ', $flags);
                    check(str_contains($commands, 'rm /remote/var/backups/') === $yes, "$label: remote cleanup");
                    check(str_contains($commands, 'rm var/backups/') === $yes, "$label: local cleanup");
                    check(str_contains($commands, $mode === 'mysql' ? 'mysql ' : 'contao:backup:restore'), "$label: imports database");
                    if ($mode === 'mysql') {
                        check(str_contains($commands, 'contao:migrate'), "$label: runs migrations");
                        check(str_contains($commands, '--no-interaction') === $yes, "$label: unattended migration");
                    } else {
                        check(in_array('local-console contao:backup:restore backup.sql ', $runner->commands, true), "$label: preserves the configured restore command and filename");
                    }
                }
            } finally {
                Context::pop();
            }
        }

        $input = new ArgvInput(['dep'], $definition);
        $answers = fopen('php://memory', 'r+');
        fwrite($answers, $mode === 'contao' ? "yes\nno\n" : "no\n");
        rewind($answers);
        $input->setStream($answers);
        $deployer['input'] = $input;
        $runner->commands = [];
        $deployer->tasks->get('db:pull:' . $mode)->run($context);
        fclose($answers);
        $commands = implode("\n", $runner->commands);
        check(!str_contains($commands, $mode === 'mysql' ? 'mysql ' : 'contao:backup:restore'), "$mode: declining import cancels restore");
        check(!str_contains($commands, 'rm '), "$mode: declining import preserves dumps");
        check(!str_contains($commands, 'contao:migrate'), "$mode: declining import skips migrations");
    }
    echo "PASS: pull modes, alias, -y, --yes, -y -n, unchanged defaults, and interactive cancellation\n";
} finally {
    if (is_file('var/tmp/.env.remote')) {
        unlink('var/tmp/.env.remote');
    }
    unlink('.env.local');
    rmdir('var/tmp');
    rmdir('var');
    chdir($originalDirectory);
    rmdir($directory);
}
