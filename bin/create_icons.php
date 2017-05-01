#!/usr/bin/env php
<?php

$icons = array(
    '#ffffff' => array(
        'mark-github' => 'github',

        'repo' => 'repo',
        'repo-forked' => 'fork',
        'mirror' => 'mirror',

        'person' => 'user',
        'organization' => 'organization',
        'star' => 'stars',
        'gist' => 'gists',

        'issue-opened' => 'issue',
        'git-pull-request' => 'pull-request',
        'milestone' => 'milestone',
        'file' => 'file',
        'graph' => 'graphs',
        'pulse' => 'pulse',
        'book' => 'wiki',
        'git-commit' => 'commits',
        'git-branch' => 'branch',
        'repo-clone' => 'clone',
        'tag' => 'releases',

        'dashboard' => 'dashboard',
        'gear' => 'settings',
        'bell' => 'notifications',

        'search' => 'search',

        'cloud-download' => 'update',
        'sign-out' => 'logout',
    ),
    '#e9dba5' => array(
        'repo' => 'private-repo',
        'repo-forked' => 'private-fork',
        'mirror' => 'private-mirror',
    ),
);

$dir = __DIR__.'/../icons/';

$baseImg = new Imagick();
$baseImg->newImage(256, 256, new ImagickPixel('transparent'));
$baseImg->setImageFormat('png');

$draw = new ImagickDraw();
$draw->setFillColor('#444444');
$draw->roundRectangle(0, 0, 256, 256, 50, 50);
$baseImg->drawImage($draw);

foreach ($icons as $color => $set) {
    foreach ($set as $svgName => $name) {
        $img = clone $baseImg;

        $svg = new Imagick();
        $svg->setBackgroundColor(new ImagickPixel('transparent'));
        $svg->setResolution(1020, 1020);

        $file = file_get_contents(__DIR__.'/../node_modules/octicons/build/svg/'.$svgName.'.svg');
        $file = str_replace('<path ', '<path fill="'.$color.'" ', $file);
        $file = '<?xml version="1.0" encoding="UTF-8"?>'."\n".$file;

        $svg->readImageBlob($file);

        $x = (256 - $svg->getImageWidth()) / 2;
        $y = (256 - $svg->getImageHeight()) / 2;

        $img->compositeImage($svg, Imagick::COMPOSITE_DEFAULT, $x, $y);
        $img->writeImage($dir.$name.'.png');
    }
}

rename($dir.'github.png', __DIR__.'/../icon.png');
