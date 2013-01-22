<?php

require 'class.php';

gh::init();

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

        $c = gh::request('https://github.com/session', $status, true, null, array('authenticity_token' => gh::getToken(), 'login' => $parts[2], 'password' => $password));
        if ($status === 302) {
          echo 'Successfully logged in';
          gh::deleteCache();
          gh::setUser($parts[2]);
        } else {
          echo 'Login failed';
        }
        break;

      case 'logout':
        gh::deleteCookies();
        gh::deleteCache();
        echo 'Successfully logged out';
        break;

      case 'delete-cache':
        gh::deleteCache();
        echo 'Successfully deleted cache';
        break;
    }
    break;

  case 'url':
    exec('osascript -e "open location \"' . $parts[1] . '\""');
    break;

  case 'follow':
    $c = gh::request('https://github.com/command_bar/' . $parts[1] . '/follow', $status, true, gh::getToken());
    echo $c == 'true' ? 'You are now following ' . $parts[1] : 'Failed to follow ' . $parts[1];
    break;

  case 'unfollow':
    $c = gh::request('https://github.com/command_bar/' . $parts[1] . '/unfollow', $status, true, gh::getToken());
    echo $c == 'true' ? 'You are no longer following ' . $parts[1] : 'Failed to unfollow ' . $parts[1];
    break;

  case 'watch':
    $c = gh::request('https://github.com/command_bar/' . $parts[1] . '/watch', $status, true, gh::getToken());
    echo $c == 'true' ? 'You are now watching ' . $parts[1] : 'Failed to watch ' . $parts[1];
    break;

  case 'unwatch':
    $c = gh::request('https://github.com/command_bar/' . $parts[1] . '/unwatch', $status, true, gh::getToken());
    echo $c == 'true' ? 'You are no longer watching ' . $parts[1] : 'Failed to unwatch ' . $parts[1];
    break;

}
