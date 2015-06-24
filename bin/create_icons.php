#!/usr/bin/env php
<?php

$icons = array(
    '#ffffff' => array(
        'f00a' => 'github',

        'f001' => 'repo',
        'f002' => 'fork',
        'f024' => 'mirror',

        'f018' => 'user',
        'f037' => 'organization',
        'f06b' => 'contributions',
        'f034' => 'activity',
        'f02a' => 'stars',
        'f00e' => 'gists',

        'f026' => 'issue',
        'f009' => 'pull-request',
        'f075' => 'milestone',
        'f011' => 'file',
        'f031' => 'admin',
        'f043' => 'graphs',
        'f085' => 'pulse',
        'f007' => 'wiki',
        'f01f' => 'commits',
        'f020' => 'branch',
        'f04c' => 'clone',

        'f07d' => 'dashboard',
        'f02f' => 'settings',
        'f0cf' => 'notifications',

        'f02e' => 'search',

        'f00b' => 'update',
        'f032' => 'logout',
    ),
    '#e9dba5' => array(
        'f06a' => 'private-repo',
        'f002' => 'private-fork',
        'f024' => 'private-mirror',
    ),
);

$dir = __DIR__ . '/../icons/';

$baseImg = new Imagick();
$baseImg->newImage(256, 256, new ImagickPixel('transparent'));
$baseImg->setImageFormat('png');

$draw = new ImagickDraw();
$draw->setFillColor('#444444');
$draw->roundRectangle(0, 0, 256, 256, 50, 50);
$baseImg->drawImage($draw);

$draw->setFont('octicons.ttf');
$draw->setFontSize(170);
$draw->setGravity(Imagick::GRAVITY_CENTER);

foreach ($icons as $color => $set) {
    $draw->setFillColor($color);
    foreach ($set as $char => $name) {
        $img = clone $baseImg;
        $img->annotateImage($draw, 0, 0, 0, json_decode('"\u'.$char.'"'));
        $img->writeImage($dir . $name . '.png');
    }
}

rename($dir . 'github.png', __DIR__ . '/../icon.png');
