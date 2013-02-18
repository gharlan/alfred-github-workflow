<?php

require 'workflow.php';

$query = ltrim($argv[1]);
$parts = explode(' ', $query);

Workflow::init($query);

if (Workflow::checkUpdate()) {
  $cmds = array(
    'update' => 'There is an update for this Alfred workflow',
    'deactivate autoupdate' => 'Deactivate auto updating this Alfred Workflow'
  );
  foreach ($cmds as $cmd => $desc) {
    Workflow::addItem(Item::create()
      ->prefix('gh ')
      ->title('> ' . $cmd)
      ->subtitle($desc)
      ->arg('> ' . str_replace(' ', '-', $cmd))
      ->randomUid()
    , false);
  }
  print Workflow::getItemsAsXml();
  exit;
}

$users = Workflow::requestCacheJson('https://github.com/command_bar/users', 'users');

if (empty($users)) {

  $user = null;
  if (count($parts) > 1 && $parts[0] == '>' && $parts[1] == 'login' && isset($parts[2])) {
    $user = $parts[2];
  }
  Workflow::addItem(Item::create()
    ->prefix('gh ')
    ->title('> login ' . $user)
    ->subtitle('Log in to GitHub')
    ->arg('> login ' . $user)
    ->valid((bool) $user, '<user>')
  );
  print Workflow::getItemsAsXml();
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

if (!$isSystem) {

  if (!$isUser && $isRepo && isset($parts[1])) {

    if (isset($parts[1][0]) && in_array($parts[1][0], array('#', '@'))) {

      if ($parts[1][0] == '#' && isset($parts[1][1]) && intval($parts[1][1]) === 0 && strlen($parts[1]) < 4) {
        Workflow::addItem(Item::create()
          ->title($parts[0] . ' ' . $parts[1])
          ->subtitle('Search an issue, type at least 3 letters')
          ->valid(false)
        );
      } else {
        $pathAdd = '';
        $compareDescription = false;
        if ($parts[1][0] == '@') {
          $type = 'branch';
          $path = 'branches';
          $url = 'tree';
        } else {
          $type = 'issue';
          $path = 'issues';
          $url = 'issues';
          if (strlen($parts[1]) > 3 && intval($parts[1][1]) == 0) {
            $pathAdd = '_search?q=' . substr($parts[1], 1);
            $compareDescription = true;
          }
        }
        $subs = Workflow::requestCacheJson('https://github.com/command_bar/' . $parts[0] . '/' . $path . $pathAdd, $path);
        foreach ($subs as $sub) {
          Workflow::addItem(Item::create()
            ->title($parts[0] . ' ' . $sub->command)
            ->comparator($parts[0] . ' ' . ($compareDescription ? '#' . $sub->description : $sub->command))
            ->subtitle($sub->description)
            ->arg('url https://github.com/' . $parts[0] . '/' . $url . '/' . substr($sub->command, 1))
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
        Workflow::addItem(Item::create()
          ->title($parts[0] . ' ' . $key)
          ->subtitle($sub)
          ->arg('url https://github.com/' . $parts[0] . '/' . $key)
        );
      }
      foreach (array('watch', 'unwatch') as $key) {
        Workflow::addItem(Item::create()
          ->title($parts[0] . ' ' . $key)
          ->subtitle(ucfirst($key) . ' ' . $parts[0])
          ->arg($key . ' ' . $parts[0])
        );
      }
      if (empty($parts[1])) {
        $subs = array(
          '#' => 'Show a specific issue by number',
          '@' => 'Show a specific branch'
        );
        foreach ($subs as $key => $subtitle) {
          Workflow::addItem(Item::create()
            ->title($parts[0] . ' ' . $key)
            ->subtitle($subtitle)
            ->arg($key . ' ' . $parts[0])
            ->valid(false)
          );
        }
      }

    }

  } elseif (!$isUser) {

    $path = $isRepo ? 'repos_for/' . $queryUser : 'repos';
    $repos = Workflow::requestCacheJson('https://github.com/command_bar/' . $path, 'repositories');

    foreach ($repos as $repo) {
      Workflow::addItem(Item::create()
        ->title($repo->command . ' ')
        ->subtitle($repo->description)
        ->arg('url https://github.com/' . $repo->command)
        ->prio(3)
      );
    }

  }

  if ($isUser && isset($parts[1])) {
    foreach (array('follow', 'unfollow') as $key) {
      Workflow::addItem(Item::create()
        ->title($parts[0] . ' ' . $key)
        ->subtitle(ucfirst($key) . ' ' . $queryUser)
        ->arg($key . ' ' . $queryUser)
      );
    }
  } elseif (!$isRepo) {
    foreach ($users as $user) {
      $name = substr($user->command, 1);
      Workflow::addItem(Item::create()
        ->prefix('@', false)
        ->title($name . ' ')
        ->subtitle($user->description)
        ->arg('url https://github.com/' . $name)
        ->prio(2)
      );
    }
  }

  $myPages = array(
    'dashboard'     => array('', 'View your dashboard'),
    'pulls'         => array('dashboard/pulls', 'View your pull requests'),
    'issues'        => array('dashboard/issues', 'View your issues'),
    'stars'         => array('stars', 'View your starred repositories'),
    'profile'       => array(Workflow::getConfig('user'), 'View your public user profile'),
    'settings'      => array('settings', 'View or edit your account settings'),
    'notifications' => array('notifications', 'View all your notifications')
  );
  foreach ($myPages as $key => $my) {
    Workflow::addItem(Item::create()
      ->title('my ' . $key)
      ->subtitle($my[1])
      ->arg('url https://github.com/' . $my[0])
      ->prio(1)
    );
  }

  Workflow::sortItems();

  if ($query) {
    $path = $isUser ? $queryUser : 'search?q=' . urlencode($query);
    Workflow::addItem(Item::create()
      ->title("Search GitHub for '$query'")
      ->arg('url https://github.com/' . $path)
      ->autocomplete(false)
    , false);
  }

} else {

  $cmds = array(
    'logout' => 'Log out from GitHub (only this Alfred Workflow)',
    'delete cache' => 'Delete GitHub Cache (only for this Alfred Workflow)',
    'update' => 'Update this Alfred workflow'
  );
  if (Workflow::getConfig('autoupdate', true)) {
    $cmds['deactivate autoupdate'] = 'Deactivate auto updating this Alfred Workflow';
  } else {
    $cmds['activate autoupdate'] = 'Activate auto updating this Alfred Workflow';
  }
  foreach ($cmds as $cmd => $desc) {
    Workflow::addItem(Item::create()
      ->prefix('gh ')
      ->title('> ' . $cmd)
      ->subtitle($desc)
      ->arg('> ' . str_replace(' ', '-', $cmd))
    );
  }

  Workflow::sortItems();

}

print Workflow::getItemsAsXml();
