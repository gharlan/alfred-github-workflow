<?php

require_once 'workflow.php';

class Action
{
    public static $skipDialogs = false;

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

            case 'user':
                return self::dispatchUser($parts, $enterprise);
        }

        return '';
    }

    private static function confirmDialog(string $title, string $message): bool
    {
        if (self::$skipDialogs) {
            return true;
        }
        $safeTitle = str_replace('"', '\\"', $title);
        $safeMessage = str_replace('"', '\\"', $message);
        $script = 'display dialog "'.$safeMessage.'" buttons {"Cancel", "Delete"} default button "Cancel" cancel button "Cancel" with title "'.$safeTitle.'"';
        exec('osascript -e '.escapeshellarg($script).' 2>&1', $output, $exitCode);

        return 0 === $exitCode;
    }

    private static function showSimpleAlert(string $title, string $message): void
    {
        $safeTitle = str_replace('"', '\\"', $title);
        $safeMessage = str_replace('"', '\\"', $message);
        $script = 'display dialog "'.$safeMessage.'" buttons {"OK"} default button "OK" with title "'.$safeTitle.'"';
        exec('osascript -e '.escapeshellarg($script).' > /dev/null 2>&1 &');
    }

    private static function showAlert(string $title, string $message): void
    {
        $safeTitle = str_replace('"', '\\"', $title);
        $safeMessage = str_replace('"', '\\"', $message);
        $script = 'set r to display dialog "'.$safeMessage.'" buttons {"OK", "Open GitHub"} default button "Open GitHub" with title "'.$safeTitle.'"'."\n".
            'if button returned of r is "Open GitHub" then'."\n".
            '    open location "https://github.com"'."\n".
            'end if';
        exec('osascript -e '.escapeshellarg($script).' > /dev/null 2>&1 &');
    }

    private static function dispatchUser(array $parts, bool $enterprise): string
    {
        $action = $parts[2] ?? '';
        $label = $parts[3] ?? '';

        switch ($action) {
            case 'add':
                if ($enterprise) {
                    return 'Multi-account is only supported for github.com.';
                }
                if ('' === $label) {
                    return 'Usage: gh user add <label>';
                }
                foreach (Workflow::listAccounts() as $account) {
                    if ($account['label'] === $label) {
                        return 'Account "'.$label.'" already exists. Use "gh user update '.$label.'" to refresh the token.';
                    }
                }
                Workflow::setConfig('pending_account_label', $label);
                Workflow::startServer();
                $state = version_compare(PHP_VERSION, '5.4', '<') ? 'm' : '';
                $url = 'https://github.com/login/oauth/authorize?client_id=2d4f43826cb68e11c17c&scope=repo&state='.$state.'&login='.urlencode($label).'&prompt=select_account';
                exec('open '.escapeshellarg($url));

                return 'Authorize in browser. Token will be saved as "'.$label.'". Then run "gh > user switch '.$label.'".';

            case 'login':
                if ($enterprise) {
                    return 'Multi-account is only supported for github.com.';
                }
                if ('' === $label) {
                    return 'Usage: gh user login <label> <token>';
                }
                $token = $parts[4] ?? '';
                if ('' === $token) {
                    return 'Usage: gh user login <label> <token>';
                }
                $existing = null;
                foreach (Workflow::listAccounts() as $account) {
                    if ($account['label'] === $label) {
                        $existing = $account;
                        break;
                    }
                }
                if ($existing) {
                    Workflow::updateAccountToken((int) $existing['id'], $token);
                    $accountId = (int) $existing['id'];
                } else {
                    $accountId = Workflow::addAccount($label, $token);
                }
                if (!Workflow::getActiveAccount()) {
                    Workflow::setActiveAccount($accountId);
                }

                return 'Saved token for "'.$label.'". Run "gh > user switch '.$label.'" to activate.';

            case 'switch':
                if ('' === $label) {
                    return 'Usage: gh user switch <label>';
                }
                foreach (Workflow::listAccounts() as $account) {
                    if ($account['label'] === $label) {
                        Workflow::setActiveAccount((int) $account['id']);
                        self::showAlert(
                            'Switched to '.$label,
                            'Alfred is now using the "'.$label.'" GitHub account. Switch your browser session to match if needed.'
                        );

                        return '';
                    }
                }

                return 'Account "'.$label.'" not found';

            case 'update':
                if ($enterprise) {
                    return 'Multi-account is only supported for github.com.';
                }
                if ('' === $label) {
                    return 'Usage: gh user update <label>';
                }
                $found = false;
                foreach (Workflow::listAccounts() as $account) {
                    if ($account['label'] === $label) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return 'Account "'.$label.'" not found';
                }
                Workflow::setConfig('pending_account_label', $label);
                Workflow::startServer();
                $state = version_compare(PHP_VERSION, '5.4', '<') ? 'm' : '';
                $url = 'https://github.com/login/oauth/authorize?client_id=2d4f43826cb68e11c17c&scope=repo&state='.$state.'&login='.urlencode($label).'&prompt=select_account';
                exec('open '.escapeshellarg($url));

                return 'Authorize in browser to refresh token for "'.$label.'".';

            case 'delete':
                if ('' === $label) {
                    return 'Usage: gh user delete <label>';
                }
                foreach (Workflow::listAccounts() as $account) {
                    if ($account['label'] === $label) {
                        try {
                            if (!self::confirmDialog('Delete "'.$label.'"?', 'This will remove the account and its cached data.')) {
                                return '';
                            }
                            Workflow::removeAccount((int) $account['id']);
                            self::showSimpleAlert('Account Deleted', 'Removed "'.$label.'".');

                            return '';
                        } catch (\RuntimeException $e) {
                            return 'Cannot delete active account "'.$label.'" — switch first';
                        }
                    }
                }

                return 'Account "'.$label.'" not found';
        }

        return 'Unknown user command: '.$action;
    }
}

if (isset($argv[1])) {
    $query = trim($argv[1]);

    if ('>' !== $query[0] && 0 !== strpos($query, 'e >')) {
        if ('.git' == substr($query, -4)) {
            $query = 'x-github-client://openRepo/'.substr($query, 0, -4);
        }
        exec('open '.escapeshellarg($query));

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
