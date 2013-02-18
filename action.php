<?php

require 'workflow.php';

Workflow::init();

$query = trim($argv[1]);
$parts = explode(' ', $query);

switch ($parts[0]) {

  case '>':
    switch ($parts[1]) {
      case 'login':
        $password = exec('osascript <<END
tell application "Alfred 2"
  activate
  set alfredPath to (path to application "Alfred 2")
  set alfredIcon to path to resource "appicon.icns" in bundle (alfredPath as alias)
  display dialog "Password for \"' . escapeshellcmd($parts[2]) . '\":" with title "GitHub Login" buttons {"OK"} default button "OK" default answer "" with icon alfredIcon with hidden answer
  set answer to text returned of result
end tell
END');

        $c = Workflow::request('https://github.com/session', $status, $etag, true, null, array('authenticity_token' => Workflow::getToken(), 'login' => $parts[2], 'password' => $password));
        if ($status === 302) {
          echo 'Successfully logged in';
          Workflow::deleteCache();
          Workflow::setConfig('user', $parts[2]);
        } else {
          echo 'Login failed';
        }
        break;

      case 'logout':
        Workflow::deleteCookies();
        Workflow::deleteCache();
        echo 'Successfully logged out';
        break;

      case 'delete-cache':
        Workflow::deleteCache();
        echo 'Successfully deleted cache';
        break;

      case 'activate-autoupdate':
        Workflow::setConfig('autoupdate', true);
        echo 'Activated auto updating';
        break;

      case 'deactivate-autoupdate':
        Workflow::setConfig('autoupdate', false);
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
        //$phar->extractTo(__DIR__ . '/test', null, true);
        foreach ($phar as $path => $file) {
          copy($path, __DIR__ . '/' . $file->getFilename());
        }
        unlink($zip);
        echo 'Successfully updated the GitHub Workflow';
        break;
    }
    break;

  case 'url':
    exec('osascript -e "open location \"' . $parts[1] . '\""');
    break;

  case 'follow':
    $c = Workflow::request('https://github.com/command_bar/' . $parts[1] . '/follow', $status, $etag, true, Workflow::getToken());
    echo $c == 'true' ? 'You are now following ' . $parts[1] : 'Failed to follow ' . $parts[1];
    break;

  case 'unfollow':
    $c = Workflow::request('https://github.com/command_bar/' . $parts[1] . '/unfollow', $status, $etag, true, Workflow::getToken());
    echo $c == 'true' ? 'You are no longer following ' . $parts[1] : 'Failed to unfollow ' . $parts[1];
    break;

  case 'watch':
    $c = Workflow::request('https://github.com/command_bar/' . $parts[1] . '/watch', $status, $etag, true, Workflow::getToken());
    echo $c == 'true' ? 'You are now watching ' . $parts[1] : 'Failed to watch ' . $parts[1];
    break;

  case 'unwatch':
    $c = Workflow::request('https://github.com/command_bar/' . $parts[1] . '/unwatch', $status, $etag, true, Workflow::getToken());
    echo $c == 'true' ? 'You are no longer watching ' . $parts[1] : 'Failed to unwatch ' . $parts[1];
    break;

}
