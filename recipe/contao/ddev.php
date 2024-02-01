<?php

namespace Deployer;

set('ddev_global_commands', '.ddev/.global_commands');

desc('Run yarn encore production build task');
task('ddev:yp', function () {
    $out = runLocally('{{ddev_global_commands}}/web/encore_yp');
    writeln($out);
})->once();

desc('Run yarn encore development build task');
task('ddev:yd', function () {
    $out = runLocally('{{ddev_global_commands}}/web/encore_yd');
    writeln($out);
})->once();

desc('Run ddev cache clear');
task('ddev:cclear', function () {
    $out = runLocally('{{ddev_global_commands}}/web/contao_cache_clear');
    writeln($out);
})->once();
