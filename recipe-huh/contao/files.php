<?php

namespace Deployer;

desc('Initializes files/ by sourcing all available files from remote.');
task('files:source', static function () {
    if (!askConfirmation('Download remote files initially? (You cannot have symlinks in your local files directory yet.)')) {
        return;
    }
    download("{{release_or_current_path}}/files/", 'files/');
    info('Download of files/ directory completed');
});

desc('Download files from remote');
task('files:pull', function () {
    $cmd = 'rsync -ave ssh --checksum -L {{remote_user}}@{{hostname}}:"{{release_or_current_path}}/files/" "{{rsync_src}}/files/"';
    info("About to run: " . $cmd);
    if (!askConfirmation('Are you sure you want to download files from remote?')) {
        return;
    }
    runLocally($cmd);
});
