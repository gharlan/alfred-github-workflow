<?php

// workflow.php uses relative requires for item.php and curl.php, so the
// include path must point at the repo root when it is loaded.
chdir(__DIR__.'/..');
require_once __DIR__.'/../OAuthState.php';
require __DIR__.'/../workflow.php';
require __DIR__.'/../action.php';

/**
 * Create a fresh temp directory that will be used as alfred_workflow_data.
 * Each test gets its own directory to prevent SQLite file collisions and
 * to keep tests independent of one another.
 */
function agw_test_tmp_dir(): string
{
    $dir = sys_get_temp_dir().'/agw-test-'.bin2hex(random_bytes(8));
    mkdir($dir, 0755, true);

    return $dir;
}

/**
 * Recursively remove a test directory.
 */
function agw_test_rmrf(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }
        $path = $dir.'/'.$entry;
        if (is_dir($path)) {
            agw_test_rmrf($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/**
 * Reset the static state of the Workflow class between tests. Because
 * Workflow is a singleton-style static class, state leaks between tests
 * otherwise — fresh SQLite handles, urls, and item buffers are required.
 */
function agw_test_reset_workflow(): void
{
    $ref = new ReflectionClass(Workflow::class);
    $resets = [
        'filePids' => null,
        'fileDb' => null,
        'db' => null,
        'statements' => [],
        'enterprise' => null,
        'baseUrl' => 'https://github.com',
        'apiUrl' => 'https://api.github.com',
        'gistUrl' => 'https://gist.github.com',
        'query' => null,
        'hotkey' => null,
        'items' => [],
        'refreshUrls' => [],
        'debug' => false,
    ];
    foreach ($resets as $name => $value) {
        if ($ref->hasProperty($name)) {
            $prop = $ref->getProperty($name);
            $prop->setValue(null, $value);
        }
    }
}
