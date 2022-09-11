#!/usr/bin/env php
<?php

// https://primer.style/octicons/
$icons = [
    '#ffffff' => [
        'mark-github-16' => 'github',

        'repo-24' => 'repo',
        'repo-forked-24' => 'fork',
        'mirror-24' => 'mirror',

        'person-24' => 'user',
        'organization-24' => 'organization',
        'star-24' => 'stars',
        'logo-gist-16' => 'gists',

        'issue-opened-24' => 'issue',
        'git-pull-request-24' => 'pull-request',
        'milestone-24' => 'milestone',
        'play-24' => 'actions',
        'file-24' => 'file',
        'graph-24' => 'graphs',
        'pulse-24' => 'pulse',
        'project-24' => 'project',
        'book-24' => 'wiki',
        'git-commit-24' => 'commits',
        'git-branch-24' => 'branch',
        'repo-clone-16' => 'clone',
        'tag-24' => 'releases',

        'megaphone-24' => 'dashboard',
        'gear-24' => 'settings',
        'bell-24' => 'notifications',

        'search-24' => 'search',

        'download-24' => 'update',
        'sign-out-24' => 'logout',
    ],
    '#e9dba5' => [
        'repo-24' => 'private-repo',
        'repo-forked-24' => 'private-fork',
        'mirror-24' => 'private-mirror',
    ],
];

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

        $file = file_get_contents(__DIR__.'/../node_modules/@primer/octicons/build/svg/'.$svgName.'.svg');
        $file = str_replace('<path ', '<path fill="'.$color.'" ', $file);

        $png = shell_exec('echo '.escapeshellarg($file).' | rsvg-convert -w 170');

        $svg = new Imagick();
        $svg->setBackgroundColor(new ImagickPixel('transparent'));
        $svg->readImageBlob($png);

        $x = (int) ((256 - $svg->getImageWidth()) / 2);
        $y = (int) ((256 - $svg->getImageHeight()) / 2);

        $img->compositeImage($svg, Imagick::COMPOSITE_DEFAULT, $x, $y);
        $img->writeImage($dir.$name.'.png');
    }
}

rename($dir.'github.png', __DIR__.'/../icon.png');
