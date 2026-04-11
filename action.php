<?php

require_once 'workflow.php';

class Action
{
    public static function dispatch(array $parts, bool $enterprise): string
    {
        switch ($parts[1]) {
            case 'enterprise-url':
                Workflow::setConfig('enterprise_url', rtrim($parts[2], '/'));
                exec('osascript -e "tell application \"Alfred\" to search \"ghe \""');

                return '';

            case 'enterprise-reset':
                Workflow::removeConfig('enterprise_url');
                Workflow::removeConfig('enterprise_access_token');
                Workflow::deleteCache();

                return '';

            case 'login':
                if (isset($parts[2]) && $parts[2]) {
                    Workflow::setAccessToken($parts[2]);

                    return 'Successfully logged in';
                }
                if (!$enterprise) {
                    Workflow::startServer();
                    $state = version_compare(PHP_VERSION, '5.4', '<') ? 'm' : '';
                    $url = Workflow::getBaseUrl().'/login/oauth/authorize?client_id=2d4f43826cb68e11c17c&scope=repo&state='.$state;
                    exec('open '.escapeshellarg($url));
                }

                return '';

            case 'logout':
                Workflow::removeAccessToken();
                Workflow::deleteCache();

                return 'Successfully logged out';

            case 'delete-cache':
                Workflow::deleteCache();

                return 'Successfully deleted cache';

            case 'delete-database':
                Workflow::deleteDatabase();

                return 'Successfully deleted database';

            case 'refresh-cache':
                $curl = new Curl();
                foreach (explode(',', $parts[2]) as $url) {
                    Workflow::requestCache($url, $curl, null, false, 0, false);
                }
                $curl->execute();
                Workflow::cleanCache();

                return '';

            case 'activate-autoupdate':
                Workflow::setConfig('autoupdate', 1);

                return 'Activated auto updating';

            case 'deactivate-autoupdate':
                Workflow::setConfig('autoupdate', 0);

                return 'Deactivated auto updating';

            case 'update':
                $release = json_decode(Workflow::request('https://api.github.com/repos/gharlan/alfred-github-workflow/releases/latest'));
                if (!isset($release->assets[0]->browser_download_url)) {
                    return 'Update failed';
                }
                $response = Workflow::request($release->assets[0]->browser_download_url, null, null, false);
                if (!$response) {
                    return 'Update failed';
                }
                $path = getenv('alfred_workflow_data').'/github.alfredworkflow';
                file_put_contents($path, $response);
                exec('open '.escapeshellarg($path));

                return '';
        }

        return '';
    }
}

if (isset($argv[1])) {
    $query = trim($argv[1]);

    if ('>' !== $query[0] && 0 !== strpos($query, 'e >')) {
        if ('.git' == substr($query, -4)) {
            $query = 'x-github-client://openRepo/'.substr($query, 0, -4);
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
    echo Action::dispatch($parts, $enterprise);
}
