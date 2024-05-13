<?php

namespace Deployer;

set('ddev_global_commands', '.ddev/.global_commands');

set('ddev_cmd_yp', '{{ddev_global_commands}}/web/encore_yp');
set('ddev_cmd_yd', '{{ddev_global_commands}}/web/encore_yd');
set('ddev_cmd_cclear', '{{ddev_global_commands}}/web/contao_cache_clear');

// set the local database restore command to the contao console command within ddev
set('local_cmd_db_restore', "{{ddev_global_commands}}/web/contao_console contao:backup:restore {{db_dump_filename}} {{console_options}}");

desc('Run yarn encore production build task');
task('ddev:yp', function () {
    $out = runLocally('{{ddev_cmd_yp}}');
    writeln($out);
})->once();

desc('Run yarn encore development build task');
task('ddev:yd', function () {
    $out = runLocally('{{ddev_cmd_yd}}');
    writeln($out);
})->once();

desc('Run ddev cache clear');
task('ddev:cclear', function () {
    $out = runLocally('{{ddev_cmd_cclear}}');
    writeln($out);
})->once();
