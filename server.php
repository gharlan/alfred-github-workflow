<?php

if (preg_match('/\.(?:png|jpg|jpeg|gif)$/', $_SERVER['REQUEST_URI'])) {
    return false;
}

require 'workflow.php';
require_once 'OAuthState.php';

Workflow::init();

$token = $_GET['access_token'] ?? null;
if (!$token) {
    echo 'FAILURE (missing access_token parameter)!';
    exit;
}

$label = Workflow::getConfig('pending_account_label') ?? 'default';
Workflow::removeConfig('pending_account_label');

$existing = null;
foreach (Workflow::listAccounts() as $account) {
    if ($account['label'] === $label) {
        $existing = $account;
        break;
    }
}

if ($existing) {
    Workflow::updateAccountToken((int) $existing['id'], $token);
} else {
    Workflow::addAccount($label, $token);
}

if (!Workflow::getActiveAccount()) {
    $accounts = Workflow::listAccounts();
    foreach ($accounts as $account) {
        if ($account['label'] === $label) {
            Workflow::setActiveAccount((int) $account['id']);
            break;
        }
    }
}

Workflow::cacheWarmup();

?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title>alfred-github-workflow</title>
        <style>
            * {
                margin: 0;
                padding: 0;
            }
            body {
                background: #999;
                font-family: 'Helvetica Neue', sans-serif;
                font-size: 14pt;
            }
            div {
                padding-top: 200px;
                width: 450px;
                margin: 0 auto;
            }
            img {
                display: block;
                float: left;
                margin-right: 20px;
            }
            p {
                padding-top: 20px;
            }
            span {
                background: #444;
                color: #fff;
                padding: 5px;
                margin-bottom: 5px;
                display: inline-block;
            }
        </style>
    </head>
    <body>
        <div>
            <img src="icon.png" width="125" height="125"/>
            <p>
                <span>alfred-github-workflow is ready.</span><br>
                <span>Have fun.</span>
            </p>
        </div>
    </body>
</html>
