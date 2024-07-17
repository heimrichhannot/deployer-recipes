<?php

namespace Deployer;

desc('Ask for confirmation before deploying to production');
task('ask_production_confirmation', function () {
    if (!get('confirm_prod', true)) {
        return;
    }

    $markers = get('tags_production', ['prod', 'production', 'live']);
    $env = \strtolower(get('labels')['env'] ?? 'prod');
    $alias = get('alias');

    if (!\in_array($env, $markers) && !\in_array($alias, $markers)) {
        return;
    }

    if (!askConfirmation("Are you sure you want to deploy to production environment \"$alias\"?", true)) {
        die('Bye!');
    }
});

task('ask_confirm_prod', ['ask_production_confirmation']);
