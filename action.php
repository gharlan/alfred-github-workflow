<?php

require 'workflow.php';

Workflow::init();

$query = trim($argv[1]);
$parts = explode(' ', $query);

switch ($parts[0]) {

    case '>':
        switch ($parts[1]) {
            case 'login':
                $password = Workflow::askForPassword('GitHub Login', 'Password for "' . $parts[2] . '"');
                $content = Workflow::request('https://github.com/session', $status, $etag, true, array(
                    'authenticity_token' => Workflow::getToken(),
                    'login' => $parts[2],
                    'password' => $password
                ));
                if ($status === 200 && false === strpos($content, '<title>Sign in · GitHub</title>')) {
                    $authCode = Workflow::askForPassword('GitHub two-factor authentication', 'Authentication code');
                    $content = Workflow::request('https://github.com/sessions/two_factor', $status, $etag2, true, array(
                        'authenticity_token' => Workflow::getToken($content),
                        'otp' => $authCode
                    ));
                }
                if ($status === 302 && false !== strpos(Workflow::request('https://github.com/'), '<title>GitHub</title>')) {
                    echo 'Successfully logged in';
                    Workflow::deleteCache();
                    Workflow::setConfig('user', $parts[2]);
                } else {
                    echo 'Login failed';
                }
                break;

            case 'logout':
                Workflow::request('https://github.com/logout', $status, $etag, true, array(
                    'authenticity_token' => Workflow::getToken()
                ));
                Workflow::deleteCookies();
                Workflow::deleteCache();
                echo 'Successfully logged out';
                break;

            case 'delete-cache':
                Workflow::deleteCache();
                echo 'Successfully deleted cache';
                break;

            case 'refresh-cache':
                Workflow::requestCache($parts[2], 0, false);
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
                $c = Workflow::request('http://gh01.de/alfred/github/github.alfredworkflow', $status);
                if ($status != 200) {
                    echo 'Update failed';
                    exit;
                }
                $zip = __DIR__ . '/workflow.zip';
                file_put_contents($zip, $c);
                $phar = new PharData($zip);
                foreach ($phar as $path => $file) {
                    copy($path, __DIR__ . '/' . $file->getFilename());
                }
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
