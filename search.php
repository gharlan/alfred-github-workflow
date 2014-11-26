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
            ->icon($cmd)
            ->arg('> ' . str_replace(' ', '-', $cmd))
            ->randomUid()
        , false);
    }
    print Workflow::getItemsAsXml();
    exit;
}

if (!Workflow::getConfig('access_token') || !($userData = Workflow::requestGithubApi('/user'))) {
    Workflow::removeConfig('access_token');
    $token = null;
    if (count($parts) > 1 && $parts[0] == '>' && $parts[1] == 'login' && isset($parts[2])) {
        $token = $parts[2];
    }
    if (!$token) {
        Workflow::addItem(Item::create()
            ->prefix('gh ')
            ->title('> login')
            ->subtitle('Generate OAuth access token')
            ->arg('> login')
            ->randomUid()
        , false);
    }
    Workflow::addItem(Item::create()
        ->prefix('gh ')
        ->title('> login ' . $token)
        ->subtitle('Save OAuth access token')
        ->arg('> login ' . $token)
        ->valid((bool) $token, '<access_token>')
        ->randomUid()
    , false);
    print Workflow::getItemsAsXml();
    return;
}

Workflow::stopServer();

$isSystem = isset($query[0]) && $query[0] == '>';
$isMy = 'my' == $parts[0] && isset($parts[1]);
$isUser = isset($query[0]) && $query[0] == '@';
$isRepo = false;
$queryUser = null;
if ($isUser) {
    $queryUser = ltrim($parts[0], '@');
} elseif (($pos = strpos($parts[0], '/')) !== false) {
    $queryUser = substr($parts[0], 0, $pos);
    $isRepo = true;
}

if (!$isSystem) {

    if (!$isUser && !$isMy && $isRepo && isset($parts[1])) {

        if (isset($parts[1][0]) && in_array($parts[1][0], array('#', '@', '/'))) {

            $compareDescription = false;
            $pathAdd = '';
            switch ($parts[1][0]) {
                case '@':
                    $branches = Workflow::requestGithubApi('/repos/' . $parts[0] . '/branches');
                    foreach ($branches as $branch) {
                        Workflow::addItem(Item::create()
                            ->title('@' . $branch->name)
                            ->comparator($parts[0] . ' @' . $branch->name)
                            ->subtitle($branch->commit->sha)
                            ->icon('branch')
                            ->arg('https://github.com/' . $parts[0] . '/tree/' . $branch->name)
                        );
                    }
                    break;
                case '/':
                    $repo = Workflow::requestGithubApi('/repos/' . $parts[0]);
                    $files = Workflow::requestGithubApi('/repos/' . $parts[0] . '/git/trees/' . $repo->default_branch . '?recursive=1');
                    foreach ($files->tree as $file) {
                        if ('blob' === $file->type) {
                            Workflow::addItem(Item::create()
                                ->title(basename($file->path))
                                ->subtitle('/' . $file->path)
                                ->comparator($parts[0] . ' /' . $file->path)
                                ->icon('file')
                                ->arg('https://github.com/' . $parts[0] . '/blob/' . $repo->default_branch . '/' . $file->path)
                            );
                        }
                    }
                    break;
                case '#':
                    $issues = Workflow::requestGithubApi('/repos/' . $parts[0] . '/issues?sort=updated');
                    foreach ($issues as $issue) {
                        Workflow::addItem(Item::create()
                            ->title('#' . $issue->number)
                            ->comparator($parts[0] . ' #' . $issue->number)
                            ->subtitle($issue->title)
                            ->icon($issue->pull_request ? 'pull-request' : 'issue')
                            ->arg($issue->html_url)
                        );
                    }
                    break;
            }

        } else {

            $subs = array(
                'admin'   => array('Manage this repo'),
                'graphs'  => array('All the graphs'),
                'issues ' => array('List, show and create issues', 'issue'),
                'network' => array('See the network', 'graphs'),
                'pulls'   => array('Show open pull requests', 'pull-request'),
                'pulse'   => array('See recent activity'),
                'wiki'    => array('Pull up the wiki'),
                'commits' => array('View commit history')
            );
            foreach ($subs as $key => $sub) {
                Workflow::addItem(Item::create()
                    ->title($parts[0] . ' ' . $key)
                    ->subtitle($sub[0])
                    ->icon(isset($sub[1]) ? $sub[1] : $key)
                    ->arg('https://github.com/' . $parts[0] . '/' . $key)
                );
            }
            Workflow::addItem(Item::create()
                ->title($parts[0] . ' new issue')
                ->subtitle('Create new issue')
                ->icon('issue')
                ->arg('https://github.com/' . $parts[0] . '/issues/new?source=c')
            );
            Workflow::addItem(Item::create()
                ->title($parts[0] . ' new pull')
                ->subtitle('Create new pull request')
                ->icon('pull-request')
                ->arg('https://github.com/' . $parts[0] . '/pull/new?source=c')
            );
            Workflow::addItem(Item::create()
                ->title($parts[0] . ' milestones')
                ->subtitle('View milestones')
                ->icon('milestone')
                ->arg('https://github.com/' . $parts[0] . '/issues/milestones')
            );
            if (empty($parts[1])) {
                $subs = array(
                    '#' => array('Show a specific issue by number', 'issue'),
                    '@' => array('Show a specific branch', 'branch'),
                    '/' => array('Show a blob', 'file')
                );
                foreach ($subs as $key => $sub) {
                    Workflow::addItem(Item::create()
                        ->title($parts[0] . ' ' . $key)
                        ->subtitle($sub[0])
                        ->icon($sub[1])
                        ->arg($key . ' ' . $parts[0])
                        ->valid(false)
                    );
                }
            }
            Workflow::addItem(Item::create()
                ->title($parts[0] . ' clone')
                ->subtitle('Clone this repo')
                ->icon('clone')
                ->arg('https://github.com/' . $parts[0] . '.git')
            );

        }

    } elseif (!$isUser && !$isMy) {

        if ($isRepo) {
            $urls = array('/users/' . $queryUser . '/repos', '/orgs/' . $queryUser . '/repos');
        } else {
            $urls = array();
            foreach (Workflow::requestGithubApi('/user/orgs') as $org) {
                $urls[] = '/orgs/' . $org->login . '/repos';
            }
            array_push($urls, '/user/starred', '/user/subscriptions', '/user/repos');
        }
        $repos = array();
        foreach ($urls as $prio => $url) {
            $urlRepos = Workflow::requestGithubApi($url);
            foreach ($urlRepos as $repo) {
                $repo->prio = $prio;
                $repos[$repo->id] = $repo;
            }
        }
        foreach ($repos as $repo) {
            $icon = 'repo';
            if ($repo->fork) {
                $icon = 'fork';
            } elseif ($repo->mirror_url) {
                $icon = 'mirror';
            }
            if ($repo->private) {
                $icon = 'private-' . $icon;
            }
            Workflow::addItem(Item::create()
                ->title($repo->full_name . ' ')
                ->subtitle($repo->description)
                ->icon($icon)
                ->arg('https://github.com/' . $repo->full_name)
                ->prio(30 + $repo->prio)
            );
        }

    }

    if ($isUser && isset($parts[1])) {
        $subs = array(
            'contributions' => array($queryUser, "View $queryUser's contributions"),
            'repositories'  => array($queryUser . '?tab=repositories', "View $queryUser's repositories", 'repo'),
            'activity'      => array($queryUser . '?tab=activity', "View $queryUser's public activity"),
            'stars'         => array('stars/' . $queryUser, "View $queryUser's stars")
        );
        $prio = count($subs);
        foreach ($subs as $key => $sub) {
            Workflow::addItem(Item::create()
                ->prefix('@', false)
                ->title($queryUser . ' ' . $key)
                ->subtitle($sub[1])
                ->icon(isset($sub[2]) ? $sub[2] : $key)
                ->arg('https://github.com/' . $sub[0])
                ->prio($prio--)
            );
        }
    } elseif (!$isMy) {
        if (!$isRepo) {
            $users = Workflow::requestGithubApi('/user/following');
            foreach ($users as $user) {
                Workflow::addItem(Item::create()
                    ->prefix('@', false)
                    ->title($user->login . ' ')
                    ->subtitle($user->type)
                    ->arg($user->html_url)
                    ->icon(lcfirst($user->type))
                    ->prio(20)
                );
            }
        }
        Workflow::addItem(Item::create()
            ->title('my ')
            ->subtitle('Dashboard, settings, and more')
            ->prio(10)
            ->valid(false)
        );
    } else {
        $myPages = array(
            'dashboard'     => array('', 'View your dashboard'),
            'pulls'         => array('pulls', 'View your pull requests', 'pull-request'),
            'issues'        => array('issues', 'View your issues', 'issue'),
            'stars'         => array('stars', 'View your starred repositories'),
            'profile'       => array($userData->login, 'View your public user profile', 'user'),
            'settings'      => array('settings', 'View or edit your account settings'),
            'notifications' => array('notifications', 'View all your notifications')
        );
        foreach ($myPages as $key => $my) {
            Workflow::addItem(Item::create()
                ->title('my ' . $key)
                ->subtitle($my[1])
                ->icon(isset($my[2]) ? $my[2] : $key)
                ->arg('https://github.com/' . $my[0])
                ->prio(1)
            );
        }
    }

    Workflow::sortItems();

    if ($query) {
        $path = $isUser ? $queryUser : 'search?q=' . urlencode($query);
        Workflow::addItem(Item::create()
            ->title("Search GitHub for '$query'")
            ->icon('search')
            ->arg('https://github.com/' . $path)
            ->autocomplete(false)
        , false);
    }

} else {

    $cmds = array(
        'delete cache' => 'Delete GitHub Cache',
        'logout' => 'Log out this workflow',
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
            ->icon($cmd)
            ->arg('> ' . str_replace(' ', '-', $cmd))
        );
    }

    Workflow::sortItems();

}

print Workflow::getItemsAsXml();
