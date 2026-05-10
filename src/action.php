<?php

require __DIR__.'/workflow.php';

assert(isset($argv[1]));
$query = trim($argv[1]);

if ('>' !== $query[0] && !str_starts_with($query, 'e >')) {
    if ('.git' == substr($query, -4)) {
        $query = 'x-github-client://openRepo/'.substr($query, 0, -4);
    }
    exec('open '.$query);

    return;
}

$enterprise = str_starts_with($query, 'e ');
if ($enterprise) {
    $query = substr($query, 2);
}
$parts = explode(' ', $query);

Workflow::init($enterprise);

switch ($parts[1]) {
    case 'enterprise-url':
        Workflow::setConfig('enterprise_url', rtrim($parts[2], '/'));
        exec('osascript -e "tell application \"Alfred\" to search \"ghe \""');
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
            $url = Workflow::getBaseUrl().'/login/oauth/authorize?client_id=2d4f43826cb68e11c17c&scope=repo';
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
        $fetcher = new Fetcher();
        $options = new FetchOptions(maxAgeMinutes: 0, refreshInBackground: false);
        foreach (explode(',', $parts[2]) as $url) {
            $fetcher->queueUrl($url, null, $options);
        }
        $fetcher->run();
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
        $release = json_decode(Fetcher::requestRaw('https://api.github.com/repos/gharlan/alfred-github-workflow/releases/latest'));
        if (!isset($release->assets[0]->browser_download_url)) {
            echo 'Update failed';
            exit;
        }
        $response = Fetcher::requestRaw($release->assets[0]->browser_download_url, auth: false);
        if (!$response) {
            echo 'Update failed';
            exit;
        }
        $path = getenv('alfred_workflow_data').'/github.alfredworkflow';
        file_put_contents($path, $response);
        exec('open '.escapeshellarg($path));
        break;
}
