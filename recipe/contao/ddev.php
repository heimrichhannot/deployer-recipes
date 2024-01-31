<?php

namespace Deployer;

desc('Run yarn encore production build task');
task('ddev:yarn:prod', function () {
    runLocally('ddev yp');
});
