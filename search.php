<?php

require 'functions.php';

$query = $argv[1];

if ($query == '') {
  $items = array();
  $items['main'] = array(
    'arg' => 'url https://github.com',
    'title' => 'Open GitHub Main Page'
  );
  $items['command']  = array(
    'title' => 'gh …',
    'subtitle' => 'Search or type a command',
    'valid' => 'no',
    'autocomplete' => ' '
  );
  print array2xml($items);
  return;
}

$query = ltrim($query);
$parts = explode(' ', $query);

$users = json_decode(request_cache('https://github.com/command_bar/users'), true);
$users = $users['users'];

if (empty($users)) {

  $user = null;
  if (count($parts) > 1 && $parts[0] == '>' && $parts[1] == 'login') {
    $user = isset($parts[2]) ? $parts[2] : '';
  }
  $arg = '> login '.($user ?: '<user>');
  $item = array(
    'arg' => $arg,
    'title' => 'gh '.$arg,
    'subtitle' => 'Log in to GitHub'
  );
  if (!$user) {
    $item['valid'] = 'no';
    $item['autocomplete'] = ' > login ';
  }

  print array2xml(array('login' => $item));
  return;

}

$isSystem = isset($query[0]) && $query[0] == '>';
$isUser = isset($query[0]) && $query[0] == '@';
if ($isUser) {
  $queryUser = ltrim($parts[0], '@');
}
$isRepo = !$isUser && ($pos = strpos($parts[0], '/')) !== false;
if ($isRepo) {
  $queryUser = substr($parts[0], 0, $pos);
}

$items = array();

if (!$isSystem) {

  if (!$isUser && $isRepo && isset($parts[1])) {

    if (isset($parts[1][0]) && in_array($parts[1][0], array('#', '@'))) {

      if ($parts[1][0] == '@') {
        $type = 'branch';
        $path = 'branches';
        $url = 'tree';
      } else {
        $type = 'issue';
        $path = 'issues';
        $url = 'issues';
      }
      $subs = json_decode(request_cache('https://github.com/command_bar/'.$parts[0].'/'.$path), true);
      $subs = $subs[$path];
      foreach ($subs as $sub) {
        if (match($parts[1], $sub['command'], $ls)) {
          $name = substr($sub['command'], 1);
          $items['repo-'.$type.'-'.$parts[0].'-'.$name] = array(
            'arg' => 'url https://github.com/'.$parts[0].'/'.$url.'/'.$name,
            'title' => $parts[0].' '.$sub['command'],
            'subtitle' => $sub['description'],
            'autocomplete' => ' '.$parts[0].' '.$sub['command'],
            'levenshtein' => $ls,
            'sameChars' => sameCharsFromBeginning($parts[1], $sub['command'])
          );
        }
      }

    } else {

      $subs = array(
        'issues'  => 'List, show and create issues',
        'pulls'   => 'Show open pull requests',
        'wiki'    => 'Pull up the wiki',
        'graphs'  => 'All the graphs',
        'network' => 'See the network',
        'admin'   => 'Manage this repo'
      );
      foreach ($subs as $key => $sub) {
        if (match($parts[1], $key, $ls)) {
          $items['repo-'.$key.'-'.$parts[0]] = array(
            'arg' => 'url https://github.com/'.$parts[0].'/'.$key,
            'title' => $parts[0].' '.$key,
            'subtitle' => $sub,
            'autocomplete' => ' '.$parts[0].' '.$key,
            'levenshtein' => $ls,
            'sameChars' => sameCharsFromBeginning($parts[1], $key)
          );
        }
      }
      foreach (array('watch', 'unwatch') as $key) {
        if (match($parts[1], $key, $ls)) {
          $items['repo-'.$key.'-'.$parts[0]] = array(
            'arg' => $key.' '.$parts[0],
            'title' => $parts[0].' '.$key,
            'subtitle' => ucfirst($key).' '.$parts[0],
            'autocomplete' => ' '.$parts[0].' '.$key,
            'levenshtein' => $ls,
            'sameChars' => sameCharsFromBeginning($parts[1], $key)
          );
        }
      }
      if (empty($parts[1])) {
        $items['repo-issue-'.$parts[0]] = array(
          'title' => $parts[0].' #…',
          'subtitle' => 'Show a specific issue by number',
          'autocomplete' => ' '.$parts[0].' #',
          'valid' => 'no',
          'levenshtein' => 1,
          'sameChars' => 0
        );
        $items['repo-branch-'.$parts[0]] = array(
          'title' => $parts[0].' @…',
          'subtitle' => 'Show a specific branch',
          'autocomplete' => ' '.$parts[0].' @',
          'valid' => 'no',
          'levenshtein' => 1,
          'sameChars' => 0
        );
      }

    }

  } elseif (!$isUser) {

    $path = $isRepo ? 'repos_for/'.$queryUser : 'repos';
    $repos = json_decode(request_cache('https://github.com/command_bar/'.$path), true);
    $repos = $repos['repositories'];

    foreach ($repos as $repo) {
      $name = $repo['command'];
      if (match($query, $name, $ls)) {
        $items['repo-'.$name] = array(
          'arg' => 'url https://github.com/'.$name,
          'title' => $name,
          'subtitle' => $repo['description'],
          'autocomplete' => ' '.$name.' ',
          'levenshtein' => $ls,
          'sameChars' => sameCharsFromBeginning($query, $name),
          'type' => 1
        );
      }
    }

  }

  if ($isUser && isset($parts[1])) {
    foreach(array('follow', 'unfollow') as $key) {
      if (match($parts[1], $key, $ls)) {
        $items['user-'.$key.'-'.$queryUser] = array(
          'arg' => $key.' '.$queryUser,
          'title' => $parts[0].' '.$key,
          'subtitle' => ucfirst($key).' '.$queryUser,
          'autocomplete' => ' '.$parts[0].' '.$key,
          'levenshtein' => $ls,
          'sameChars' => 1
        );
      }
    }
  } elseif (!$isRepo) {
    foreach ($users as $user) {
      $name = substr($user['command'], 1);
      $qu = ltrim($query, '@');
      if (match($qu, $name, $ls)) {
        $items['user-'.$name] = array(
          'arg' => 'url https://github.com/'.$name,
          'title' => $user['command'],
          'subtitle' => $user['description'],
          'autocomplete' => ' '.$user['command'].' ',
          'levenshtein' => $ls,
          'sameChars' => sameCharsFromBeginning($qu, $name),
          'type' => 2
        );
      }
    }
  }

  $myPages = array(
    'dashboard'     => array('', 'View your dashboard'),
    'pulls'         => array('dashboard/pulls', 'View your pull requests'),
    'issues'        => array('dashboard/issues', 'View your issues'),
    'stars'         => array('stars', 'View your starred repositories'),
    'profile'       => array(file_get_contents(FILE_USER), 'View your public user profile'),
    'settings'      => array('settings', 'View or edit your account settings'),
    'notifications' => array('notifications', 'View all your notifications')
  );
  foreach ($myPages as $key => $my) {
    if (match($query, 'my '.$key, $ls)) {
      $items['my-'.$key] = array(
        'arg' => 'url https://github.com/'.$my[0],
        'title' => 'my '.$key,
        'subtitle' => $my[1],
        'autocomplete' => ' my '.$key,
        'levenshtein' => $ls,
        'sameChars' => sameCharsFromBeginning($query, 'my '.$key),
        'type' => 3
      );
    }
  }

  uasort($items, function (array $a, array $b) {
    if ($a['sameChars'] != $b['sameChars'])
      return $a['sameChars'] < $b['sameChars'] ? 1 : -1;
    if (isset($a['type']) && isset($b['type']) && $a['type'] != $b['type'])
      return $a['type'] > $b['type'] ? 1 : -1;
    return $a['levenshtein'] > $b['levenshtein'] ? 1 : -1;
  });

  if ($query) {
    $path = $isUser ? $queryUser : 'search?q='.urlencode($query);
    $items['search-'.$query] = array(
      'arg' => 'url https://github.com/'.$path,
      'title' => "Search GitHub for '$query'"
    );
  }

} else {

  $cmds = array(
    'logout' => 'Log out from GitHub (only this Alfred Workflow)',
    'delete cache' => 'Delete GitHub Cache (only for this Alfred Workflow)'
  );
  foreach ($cmds as $cmd => $desc) {
    if (match($query, '> '.$cmd)) {
      $arg = str_replace(' ', '-', $cmd);
      $items[$arg] = array(
        'arg' => '> '.$arg,
        'title' => 'gh > '.$cmd,
        'subtitle' => $desc,
        'autocomplete' => ' > '.$cmd
      );
    }
  }

}

print array2xml($items);
