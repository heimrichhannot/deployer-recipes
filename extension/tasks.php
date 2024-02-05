<?php

namespace Deployer;

task('ask_confirm_prod', function () {
    $env = strtolower(get('labels')['env'] ?? 'prod');
    if (!in_array($env, ['prod', 'production', 'live'])) {
        return;
    }
    if (!askConfirmation('Are you sure you want to deploy to production?', true)) {
        die('Bye!');
    }
});
