<?php

require 'workflow.php';

$query = trim($argv[1]);

if ('>' !== $query[0] && 0 !== strpos($query, 'e >')) {
    if ('.git' == substr($query, -4)) {
        $query = 'github-mac://openRepo/' . substr($query, 0, -4);
    }
    exec('osascript -e "open location \"' . $query . '\""');
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
        exec('osascript -e "tell application \"Alfred 2\" to search \"ghe \""');
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
            $url = Workflow::getBaseUrl() . '/login/oauth/authorize?client_id=2d4f43826cb68e11c17c&scope=repo&state=' . $state;
            exec('open ' . escapeshellarg($url));
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

    case 'refresh-cache':
        $curl = new Curl();
        foreach (explode(',', $parts[2]) as $url) {
            Workflow::requestCache($url, $curl, null, 0, false);
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
        $response = Workflow::request('http://gh01.de/alfred/github/github.alfredworkflow');
        if (!$response) {
            echo 'Update failed';
            exit;
        }
        $zip = __DIR__ . '/workflow.zip';
        file_put_contents($zip, $response);
        exec('unzip -o workflow.zip');
        unlink($zip);
        Workflow::deleteCache();
        echo 'Successfully updated the GitHub Workflow';
        break;
}
