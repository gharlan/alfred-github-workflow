<?php

require 'workflow.php';

Workflow::init();

if (!isset($_GET['access_token'])) {
    echo 'FAILURE (missing access_token parameter)!';
    exit;
}

Workflow::setConfig('access_token', $_GET['access_token']);

echo 'alfred-github-workflow is ready. Have fun.';
