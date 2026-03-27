<?php

/**
 * Shared Deployer recipe for Waaseyaa applications.
 *
 * Provides common tasks for artifact-upload deploys where vendor is pre-built
 * in CI and uploaded to the server (no composer on server).
 *
 * Usage in your app's deploy.php:
 *
 *   require 'recipe/common.php';
 *   require __DIR__ . '/vendor/waaseyaa/deployer/recipe/waaseyaa.php';
 *
 *   set('application', 'myapp');
 *   set('shared_files', ['.env']);
 *   set('shared_dirs', ['storage']);
 *   set('writable_dirs', ['storage']);
 *
 *   host('production')
 *       ->setHostname('myapp.example.com')
 *       ->set('remote_user', 'deployer')
 *       ->set('deploy_path', '/home/deployer/myapp');
 *
 *   // Add app-specific tasks, then define the deploy flow:
 *   task('deploy', [
 *       ...waaseyaaDeploySteps(),
 *       'myapp:migrate',        // your custom task
 *       ...waaseyaaFinalizeSteps(),
 *   ]);
 */

namespace Deployer;

set('keep_releases', 5);
set('allow_anonymous_stats', false);

// ── Shared tasks ─────────────────────────────────────────────────────────────

desc('Upload pre-built release artifact from CI');
task('waaseyaa:upload', function (): void {
    upload('.build/', '{{release_path}}/', [
        'options' => ['--recursive', '--compress'],
    ]);
});

desc('Clear Waaseyaa framework manifest cache');
task('waaseyaa:clear-manifest', function (): void {
    run('rm -f {{deploy_path}}/shared/storage/framework/packages.php 2>/dev/null '
        . '|| echo "INVALIDATED" > {{deploy_path}}/shared/storage/framework/packages.php 2>/dev/null '
        . '|| true');
});

desc('Reload PHP-FPM to pick up new release');
task('waaseyaa:php-fpm-reload', function (): void {
    run('sudo systemctl reload php8.4-fpm');
});

desc('Ensure shared runtime directories exist');
task('waaseyaa:runtime-dirs', function (): void {
    $sharedDirs = get('shared_dirs', ['storage']);
    foreach ($sharedDirs as $dir) {
        run("mkdir -p {{deploy_path}}/shared/{$dir}");
    }
});

// ── Step helpers ─────────────────────────────────────────────────────────────

/**
 * Common deploy steps before app-specific tasks.
 *
 * @return list<string>
 */
function waaseyaaDeploySteps(): array
{
    return [
        'deploy:info',
        'deploy:setup',
        'deploy:lock',
        'deploy:release',
        'waaseyaa:upload',
        'waaseyaa:runtime-dirs',
        'deploy:shared',
        'deploy:writable',
    ];
}

/**
 * Common finalization steps after app-specific tasks.
 *
 * @return list<string>
 */
function waaseyaaFinalizeSteps(): array
{
    return [
        'deploy:symlink',
        'waaseyaa:clear-manifest',
        'waaseyaa:php-fpm-reload',
        'deploy:unlock',
        'deploy:cleanup',
    ];
}

after('deploy:failed', 'deploy:unlock');
