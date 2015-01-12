<?php

require 'workflow.php';

Workflow::init();

$query = trim($argv[1]);
$parts = explode(' ', $query);

switch ($parts[0]) {

    case '>':
        switch ($parts[1]) {
            case 'login':
                if (isset($parts[2]) && $parts[2]) {
                    Workflow::setConfig('access_token', $parts[2]);
                    echo 'Successfully logged in';
                } else {
                    Workflow::startServer();
                    exec('open "https://github.com/login/oauth/authorize?client_id=2d4f43826cb68e11c17c&scope=repo&state=' . version_compare(PHP_VERSION, '5.4', '<') . '"');
                }
                break;

            case 'logout':
                Workflow::removeConfig('access_token');
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
        break;

    default:
        if ('.git' == substr($query, -4)) {
            $query = 'github-mac://openRepo/' . substr($query, 0, -4);
        }
        exec('osascript -e "open location \"' . $query . '\""');

}
