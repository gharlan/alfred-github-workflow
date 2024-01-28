<?php

require 'workflow.php';

class Search
{
    private static $enterprise;
    private static $query;
    private static $parts;
    private static $user;

    public static function run($scope, $query, $hotkey)
    {
        self::$enterprise = 'enterprise' === $scope;

        Workflow::init(self::$enterprise, $query, $hotkey);

        if (!$hotkey) {
            if ('' === $query) {
                self::addEmptyQueryCommand();

                return Workflow::getItemsAsXml();
            }
            if (' ' !== $query[0]) {
                return '';
            }
        }

        $query = ltrim($query);
        self::$query = $query;
        self::$parts = $parts = explode(' ', $query);

        if (Workflow::checkUpdate()) {
            self::addUpdateCommands();

            return;
        }

        if (self::$enterprise && !Workflow::getBaseUrl()) {
            self::addEnterpriseUrlCommand();

            return;
        }

        if (!Workflow::getAccessToken() || !(self::$user = Workflow::requestApi('/user'))) {
            self::addLoginCommands();

            return;
        }

        Workflow::stopServer();

        if (isset($query[0]) && '>' == $query[0]) {
            self::addSystemCommands();
            Workflow::sortItems();

            return;
        }

        $isSearch = 's' === $parts[0] && isset($parts[1]);
        $isUser = isset($query[0]) && '@' == $query[0];
        $isRepo = false;
        $queryUser = null;
        if ($isUser) {
            $queryUser = ltrim($parts[0], '@');
        } elseif (!$isSearch && false !== $pos = strpos($parts[0], '/')) {
            $queryUser = substr($parts[0], 0, $pos);
            $isRepo = true;
        }

        if ('my' == $parts[0] && isset($parts[1])) {
            self::addMyCommands();
        } elseif ($isSearch && strlen($query) > 5 && '@' !== substr($parts[1], 0, 1)) {
            self::addRepoSearchCommands();
        } elseif ($isSearch && strlen($query) > 6 && '@' === substr($parts[1], 0, 1)) {
            self::addUserSearchCommands();
        } elseif ($isUser && isset($parts[1])) {
            self::addUserSubCommands($queryUser);
        } elseif (!$isUser && $isRepo && isset($parts[1])) {
            self::addRepoSubCommands();
        } else {
            self::addDefaultCommands($isSearch, $isUser, $isRepo, $queryUser);
        }

        Workflow::sortItems();

        if (!$query) {
            return;
        }

        if (!$isSearch && !$isUser && !isset($parts[1])) {
            Workflow::addItem(Item::create()
                ->title('s '.$query)
                ->subtitle('Search repo (in alfred workflow)')
                ->comparator($query)
                ->autocomplete('s '.$query)
                ->icon('repo')
                ->valid(false)
            );
        }

        if (!$isSearch && !$isRepo && !isset($parts[1])) {
            $title = 's @'.ltrim($query, '@');
            Workflow::addItem(Item::create()
                ->title($title)
                ->subtitle('Search user (in alfred workflow)')
                ->comparator($query)
                ->autocomplete($title)
                ->icon('user')
                ->valid(false)
            );
        }

        if (!$isUser && $isRepo && isset($parts[1])) {
            $repoQuery = substr($query, strlen($parts[0]) + 1);
            Workflow::addItem(Item::create()
                ->title("Search '$parts[0]' for '$repoQuery'")
                ->icon('search')
                ->arg('/'.$parts[0].'/search?q='.urlencode($repoQuery))
                ->autocomplete(false)
            );
        }

        $path = $isUser ? $queryUser : 'search?q='.urlencode($query);
        $name = self::$enterprise ? 'GitHub Enterprise' : 'GitHub';
        Workflow::addItem(Item::create()
            ->title("Search $name for '$query'")
            ->icon('search')
            ->arg('/'.$path)
            ->autocomplete(false)
        );
    }

    private static function addEmptyQueryCommand()
    {
        Workflow::addItem(Item::create()
            ->title(self::$enterprise ? 'ghe' : 'gh')
            ->subtitle('Search or type a command'.(self::$enterprise ? ' (GitHub Enterprise)' : ''))
            ->comparator('')
            ->valid(false)
        );
    }

    private static function addUpdateCommands()
    {
        $cmds = [
            'update' => 'There is an update for this Alfred workflow',
            'deactivate autoupdate' => 'Deactivate auto updating this Alfred Workflow',
        ];
        foreach ($cmds as $cmd => $desc) {
            Workflow::addItem(Item::create()
                ->title('> '.$cmd)
                ->subtitle($desc)
                ->icon($cmd)
                ->arg('> '.str_replace(' ', '-', $cmd))
                ->randomUid()
            );
        }

        Workflow::addItem(Item::create()
            ->title('> changelog')
            ->subtitle('View the changelog')
            ->icon('file')
            ->arg('https://github.com/gharlan/alfred-github-workflow/blob/main/CHANGELOG.md')
            ->randomUid()
        );
    }

    private static function addEnterpriseUrlCommand()
    {
        $url = null;
        if (count(self::$parts) > 1 && '>' == self::$parts[0] && 'url' == self::$parts[1] && isset(self::$parts[2])) {
            $url = self::$parts[2];
        }
        Workflow::addItem(Item::create()
            ->title('> url '.$url)
            ->subtitle('Set the GitHub Enterprise URL')
            ->arg('> enterprise-url '.$url)
            ->valid((bool) $url, '<URL>')
            ->randomUid()
        );
    }

    private static function addLoginCommands()
    {
        Workflow::removeAccessToken();
        $token = null;
        if (count(self::$parts) > 1 && '>' == self::$parts[0] && 'login' == self::$parts[1] && isset(self::$parts[2])) {
            $token = self::$parts[2];
        }
        if (!$token && !self::$enterprise) {
            Workflow::addItem(Item::create()
                ->title('> login')
                ->subtitle('Generate OAuth access token')
                ->arg('> login')
                ->randomUid()
            );
        }
        Workflow::addItem(Item::create()
            ->title('> login '.$token)
            ->subtitle('Save access token')
            ->arg('> login '.$token)
            ->valid((bool) $token, '<access_token>')
            ->randomUid()
        );
        if (!$token && self::$enterprise) {
            Workflow::addItem(Item::create()
                ->title('> generate token')
                ->subtitle('Generate a new access token')
                ->arg('/settings/applications')
                ->randomUid()
            );
            Workflow::addItem(Item::create()
                ->title('> enterprise reset')
                ->subtitle('Reset the GitHub Enterprise URL')
                ->arg('> enterprise-reset')
                ->randomUid()
            );
        }
    }

    private static function addDefaultCommands($isSearch, $isUser, $isRepo, $queryUser)
    {
        $users = [];
        $repos = [];

        $curl = new Curl();

        if (!$isSearch && !$isUser) {
            $getRepos = function ($url, $prio) use ($curl, &$repos) {
                Workflow::requestApi($url, $curl, function ($urlRepos) use (&$repos, $prio) {
                    foreach ($urlRepos as $repo) {
                        $repo->score = 300 + $prio + ($repo->fork ? 0 : 10);
                        $repos[$repo->id] = $repo;
                    }
                });
            };
            if ($isRepo) {
                if ($queryUser != self::$user->login) {
                    $urls = ['/users/'.$queryUser.'/repos', '/orgs/'.$queryUser.'/repos'];
                } else {
                    $urls = ['/user/repos'];
                }
            } else {
                Workflow::requestApi('/user/orgs', $curl, function ($orgs) use ($getRepos) {
                    foreach ($orgs as $org) {
                        $getRepos('/orgs/'.$org->login.'/repos', 0);
                    }
                });
                $urls = ['/user/starred', '/user/subscriptions', '/user/repos'];
            }
            foreach ($urls as $prio => $url) {
                $getRepos($url, $prio + 1);
            }
        }

        if (!$isSearch && !$isRepo) {
            Workflow::requestApi('/user/following', $curl, function ($urlUsers) use (&$users) {
                $users = $urlUsers;
            });
        }

        $curl->execute();

        self::addRepos($repos);

        foreach ($users as $user) {
            Workflow::addItemIfMatches(Item::create()
                ->prefix('@', false)
                ->title($user->login.' ')
                ->subtitle($user->type)
                ->arg($user->html_url)
                ->icon(lcfirst($user->type))
                ->prio(200)
            );
        }

        Workflow::addItemIfMatches(Item::create()
            ->title('s '.substr(self::$query, 2, 4))
            ->subtitle('Search repo or @user (min 4 chars)')
            ->prio(110)
            ->valid(false)
        );

        Workflow::addItemIfMatches(Item::create()
            ->title('my ')
            ->subtitle('Dashboard, settings, and more')
            ->prio(100)
            ->valid(false)
        );
    }

    private static function addRepoSearchCommands()
    {
        $q = substr(self::$query, 2);
        $repos = Workflow::requestApi('/search/repositories?q='.urlencode($q), null, null, true);

        self::addRepos($repos, 's ');
    }

    private static function addUserSearchCommands()
    {
        $q = substr(self::$query, 3);
        $users = Workflow::requestApi('/search/users?q='.urlencode($q), null, null, true);

        self::addUsers($users, 's @');
    }

    private static function addRepos($repos, $comparatorPrefix = '')
    {
        foreach ($repos as $repo) {
            $icon = 'repo';
            if ($repo->fork) {
                $icon = 'fork';
            } elseif ($repo->mirror_url) {
                $icon = 'mirror';
            }
            if ($repo->private) {
                $icon = 'private-'.$icon;
            }
            Workflow::addItemIfMatches(Item::create()
                ->title($repo->full_name.' ')
                ->comparator($comparatorPrefix.$repo->full_name)
                ->autocomplete($repo->full_name.' ')
                ->subtitle(($repo->archived ? '[Archived] ' : '').$repo->description)
                ->icon($icon)
                ->arg('/'.$repo->full_name)
                ->prio($repo->score)
            );
        }
    }

    private static function addUsers($users, $comparatorPrefix = '')
    {
        foreach ($users as $user) {
            Workflow::addItemIfMatches(Item::create()
                ->prefix('@', false)
                ->title($user->login.' ')
                ->comparator($comparatorPrefix.$user->login)
                ->autocomplete($user->login.' ')
                ->subtitle($user->type)
                ->arg($user->html_url)
                ->icon(lcfirst($user->type))
                ->prio(isset($user->score) ? $user->score : 200)
            );
        }
    }

    private static function addRepoSubCommands()
    {
        $parts = self::$parts;
        if (isset($parts[1][0]) && in_array($parts[1][0], ['#', '@', '*', '/'])) {
            switch ($parts[1][0]) {
                case '*':
                    $commits = Workflow::requestApi('/repos/'.$parts[0].'/commits');
                    foreach ($commits as $commit) {
                        Workflow::addItemIfMatches(Item::create()
                            ->title($commit->commit->message)
                            ->comparator($parts[0].' *'.$commit->sha)
                            ->subtitle($commit->commit->author->date.'  ('.$commit->sha.')')
                            ->icon('commits')
                            ->arg('/'.$parts[0].'/commit/'.$commit->sha)
                            ->prio(strtotime($commit->commit->author->date))
                        );
                    }
                    break;
                case '@':
                    $branches = Workflow::requestApi('/repos/'.$parts[0].'/branches');
                    foreach ($branches as $branch) {
                        Workflow::addItemIfMatches(Item::create()
                            ->title('@'.$branch->name)
                            ->comparator($parts[0].' @'.$branch->name)
                            ->subtitle($branch->commit->sha)
                            ->icon('branch')
                            ->arg('/'.$parts[0].'/tree/'.$branch->name)
                        );
                    }
                    break;
                case '/':
                    $repo = Workflow::requestApi('/repos/'.$parts[0]);
                    $files = Workflow::requestApi('/repos/'.$parts[0].'/git/trees/'.$repo->default_branch.'?recursive=1');
                    foreach ($files->tree as $file) {
                        if ('blob' === $file->type) {
                            Workflow::addItemIfMatches(Item::create()
                                ->title(basename($file->path))
                                ->subtitle('/'.$file->path)
                                ->comparator($parts[0].' /'.$file->path)
                                ->icon('file')
                                ->arg('/'.$parts[0].'/blob/'.$repo->default_branch.'/'.$file->path)
                            );
                        }
                    }
                    break;
                case '#':
                    $issues = Workflow::requestApi('/repos/'.$parts[0].'/issues?sort=updated&state=all');
                    foreach ($issues as $issue) {
                        Workflow::addItemIfMatches(Item::create()
                            ->title($issue->title)
                            ->comparator($parts[0].' #'.$issue->number.' '.$issue->title)
                            ->subtitle('#'.$issue->number)
                            ->icon(isset($issue->pull_request) ? 'pull-request' : 'issue')
                            ->arg($issue->html_url)
                            ->prio(strtotime($issue->updated_at))
                        );
                    }
                    break;
            }
        } else {
            $subs = [
                'actions' => ['Show Github Actions'],
                'admin' => ['Manage this repo', 'settings'],
                'discussions' => ['Show discussions'],
                'graphs' => ['All the graphs'],
                'issues ' => ['List, show and create issues', 'issue'],
                'milestones' => ['View milestones', 'milestone'],
                'network' => ['See the network', 'graphs'],
                'projects' => ['View projects', 'project'],
                'pulls' => ['Show open pull requests', 'pull-request'],
                'pulse' => ['See recent activity'],
                'wiki' => ['Pull up the wiki'],
                'commits' => ['View commit history'],
                'releases' => ['See latest releases'],
            ];
            foreach ($subs as $key => $sub) {
                Workflow::addItemIfMatches(Item::create()
                    ->title($parts[0].' '.$key)
                    ->subtitle($sub[0])
                    ->icon(isset($sub[1]) ? $sub[1] : $key)
                    ->arg('/'.$parts[0].'/'.$key)
                );
            }
            Workflow::addItemIfMatches(Item::create()
                ->title($parts[0].' new issue')
                ->subtitle('Create new issue')
                ->icon('issue')
                ->arg('/'.$parts[0].'/issues/new/choose?source=c')
            );
            Workflow::addItemIfMatches(Item::create()
                ->title($parts[0].' new pull')
                ->subtitle('Create new pull request')
                ->icon('pull-request')
                ->arg('/'.$parts[0].'/pull/new?source=c')
            );
            if (empty($parts[1])) {
                $subs = [
                    '#' => ['Show a specific issue / pull request', 'issue'],
                    '@' => ['Show a specific branch', 'branch'],
                    '*' => ['Show a specific commit', 'commits'],
                    '/' => ['Show a blob', 'file'],
                ];
                foreach ($subs as $key => $sub) {
                    Workflow::addItemIfMatches(Item::create()
                        ->title($parts[0].' '.$key)
                        ->subtitle($sub[0])
                        ->icon($sub[1])
                        ->arg($key.' '.$parts[0])
                        ->valid(false)
                    );
                }
            }
            Workflow::addItemIfMatches(Item::create()
                ->title($parts[0].' dev')
                ->subtitle('Open repo with Visual Studio Code in browser')
                ->icon('codespaces')
                ->arg('https://github.dev/'.$parts[0])
            );
            Workflow::addItemIfMatches(Item::create()
                ->title($parts[0].' clone')
                ->subtitle('Clone this repo')
                ->icon('clone')
                ->arg('/'.$parts[0].'.git')
            );
        }
    }

    private static function addUserSubCommands($queryUser)
    {
        $subs = [
            'overview' => [$queryUser, "View $queryUser's overview", 'user'],
            'repositories' => [$queryUser.'?tab=repositories', "View $queryUser's repositories", 'repo'],
            'stars' => [$queryUser.'?tab=stars', "View $queryUser's stars"],
        ];
        $prio = count($subs) + 2;
        foreach ($subs as $key => $sub) {
            Workflow::addItemIfMatches(Item::create()
                ->title('@'.$queryUser.' '.$key)
                ->subtitle($sub[1])
                ->icon(isset($sub[2]) ? $sub[2] : $key)
                ->arg('/'.$sub[0])
                ->prio($prio--)
            );
        }
        Workflow::addItemIfMatches(Item::create()
            ->title('@'.$queryUser.' gists')
            ->subtitle("View $queryUser's' gists")
            ->icon('gists')
            ->arg(Workflow::getGistUrl().'/'.$queryUser)
            ->prio(2)
        );

        Workflow::addItemIfMatches(Item::create()
            ->title($queryUser.'/')
            ->comparator('@'.$queryUser.' ')
            ->autocomplete($queryUser.'/')
            ->subtitle("View $queryUser's' repositories (in alfred workflow)")
            ->icon('repo')
            ->prio(1)
            ->valid(false)
        );
    }

    private static function addMyCommands()
    {
        $parts = self::$parts;
        if (isset($parts[2]) && in_array($parts[1], ['pulls', 'issues'])) {
            $icon = 'pulls' === $parts[1] ? 'pull-request' : 'issue';
            $items = $icon.'s';
            $subs = [
                'created' => [$parts[1], 'View your '.$items],
                'assigned' => [$parts[1].'/assigned', 'View your assigned '.$items],
                'mentioned' => [$parts[1].'/mentioned', 'View '.$items.' that mentioned you'],
            ];
            if ('pulls' === $parts[1]) {
                $subs['review requested'] = [$parts[1].'/review-requested', 'View '.$items.' that require review'];
            }
            foreach ($subs as $key => $sub) {
                Workflow::addItemIfMatches(Item::create()
                    ->title('my '.$parts[1].' '.$key)
                    ->subtitle($sub[1])
                    ->icon($icon)
                    ->arg('/'.$sub[0])
                    ->prio(1)
                );
            }

            return;
        } elseif (isset($parts[2]) && 'repos' === $parts[1]) {
            Workflow::addItemIfMatches(Item::create()
                ->title('my '.$parts[1].' ')
                ->subtitle('View your repos')
                ->icon('repo')
                ->arg('/'.self::$user->login.'?tab=repositories')
                ->prio(1)
            );
            Workflow::addItemIfMatches(Item::create()
                ->title('my '.$parts[1].' new')
                ->subtitle('Create new repo')
                ->icon('repo')
                ->arg('/new')
                ->prio(1)
            );

            return;
        }

        $myPages = [
            'dashboard' => ['', 'View your dashboard'],
            'pulls ' => ['pulls', 'View your pull requests', 'pull-request'],
            'issues ' => ['issues', 'View your issues', 'issue'],
            'stars' => [self::$user->login.'?tab=stars', 'View your starred repositories'],
            'profile' => [self::$user->login, 'View your public user profile', 'user'],
            'settings' => ['settings', 'View or edit your account settings'],
            'notifications' => ['notifications', 'View all your notifications'],
        ];
        foreach ($myPages as $key => $my) {
            Workflow::addItemIfMatches(Item::create()
                ->title('my '.$key)
                ->subtitle($my[1])
                ->icon(isset($my[2]) ? $my[2] : rtrim($key))
                ->arg('/'.$my[0])
                ->prio(1)
            );
        }
        Workflow::addItemIfMatches(Item::create()
            ->title('my gists')
            ->subtitle('View your gists')
            ->icon('gists')
            ->arg(Workflow::getGistUrl().'/'.self::$user->login)
            ->prio(1)
        );

        Workflow::addItemIfMatches(Item::create()
            ->title('my repos ')
            ->subtitle('View your repos')
            ->icon('repo')
            ->arg('/'.self::$user->login.'?tab=repositories')
            ->prio(1)
        );
    }

    private static function addSystemCommands()
    {
        $cmds = [
            'delete cache' => 'Delete GitHub Cache',
            'logout' => 'Log out this workflow',
            'delete database' => 'Delete database (contains login, config and cache)',
            'update' => 'Update this Alfred workflow',
        ];
        if (Workflow::getConfig('autoupdate', true)) {
            $cmds['deactivate autoupdate'] = 'Deactivate auto updating this Alfred Workflow';
        } else {
            $cmds['activate autoupdate'] = 'Activate auto updating this Alfred Workflow';
        }
        if (self::$enterprise) {
            $cmds['enterprise reset'] = 'Reset the GitHub Enterprise URL';
        }
        foreach ($cmds as $cmd => $desc) {
            Workflow::addItemIfMatches(Item::create()
                ->title('> '.$cmd)
                ->subtitle($desc)
                ->icon($cmd)
                ->arg('> '.str_replace(' ', '-', $cmd))
            );
        }

        $cmds = [
            'help' => 'readme',
            'changelog' => 'changelog',
        ];
        foreach ($cmds as $cmd => $file) {
            Workflow::addItemIfMatches(Item::create()
                ->title('> '.$cmd)
                ->subtitle('View the '.$file)
                ->icon('file')
                ->arg('https://github.com/gharlan/alfred-github-workflow/blob/main/'.strtoupper($file).'.md')
            );
        }
    }
}
