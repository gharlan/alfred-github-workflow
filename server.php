<?php

require 'workflow.php';

Workflow::init();

if (!isset($_GET['access_token'])) {
    echo 'FAILURE!';
    exit;
}

Workflow::setConfig('access_token', $_GET['access_token']);

echo 'alfred-github-workflow is ready. Have fun.';
