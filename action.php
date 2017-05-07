<?php

/*
 * This file is part of the alfred-github-workflow package.
 *
 * (c) Gregor Harlan
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require 'workflow.php';

$query = trim($argv[1]);

if ('>' !== $query[0] && 0 !== strpos($query, 'e >')) {
    if ('.git' == substr($query, -4)) {
        $query = 'github-mac://openRepo/'.substr($query, 0, -4);
    }
    exec('open '.$query);
    return;
}

$enterprise = 0 === strpos($query, 'e ');
if ($enterprise) {
    $query = substr($query, 2);
}
$parts = explode(' ', $query);

Workflow::init($enterprise);

switch ($parts[1]) {
    case 'enterprise-url':
        Workflow::setConfig('enterprise_url', rtrim($parts[2], '/'));
        exec('osascript -e "tell application \"Alfred 3\" to search \"ghe \""');
        break;

    case 'enterprise-reset':
        Workflow::removeConfig('enterprise_url');
        Workflow::removeConfig('enterprise_access_token');
        Workflow::deleteCache();
        break;

    case 'login':
        if (isset($parts[2]) && $parts[2]) {
            Workflow::setAccessToken($parts[2]);
            echo 'Successfully logged in';
        } elseif (!$enterprise) {
            Workflow::startServer();
            $state = version_compare(PHP_VERSION, '5.4', '<') ? 'm' : '';
            $url = Workflow::getBaseUrl().'/login/oauth/authorize?client_id=2d4f43826cb68e11c17c&scope=repo&state='.$state;
            exec('open '.escapeshellarg($url));
        }
        break;

    case 'logout':
        Workflow::removeAccessToken();
        Workflow::deleteCache();
        echo 'Successfully logged out';
        break;

    case 'delete-cache':
        Workflow::deleteCache();
        echo 'Successfully deleted cache';
        break;

    case 'delete-database':
        Workflow::deleteDatabase();
        echo 'Successfully deleted database';
        break;

    case 'refresh-cache':
        $curl = new Curl();
        foreach (explode(',', $parts[2]) as $url) {
            Workflow::requestCache($url, $curl, null, false, 0, false);
        }
        $curl->execute();
        Workflow::cleanCache();
        break;

    case 'activate-autoupdate':
        Workflow::setConfig('autoupdate', 1);
        echo 'Activated auto updating';
        break;

    case 'deactivate-autoupdate':
        Workflow::setConfig('autoupdate', 0);
        echo 'Deactivated auto updating';
        break;

    case 'update':
        $release = json_decode(Workflow::request('https://api.github.com/repos/gharlan/alfred-github-workflow/releases/latest'));
        if (!isset($release->assets[0]->browser_download_url)) {
            echo 'Update failed';
            exit;
        }
        $response = Workflow::request($release->assets[0]->browser_download_url, null, null, false);
        if (!$response) {
            echo 'Update failed';
            exit;
        }
        $path = getenv('alfred_workflow_data').'/github.alfredworkflow';
        file_put_contents($path, $response);
        exec('open '.escapeshellarg($path));
        break;
}
