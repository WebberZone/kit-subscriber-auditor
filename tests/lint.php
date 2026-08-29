#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $files[] = $file->getPathname();
}
sort($files);

$failures = 0;
foreach ($files as $file) {
    $command = 'php -l ' . escapeshellarg($file);
    exec($command, $output, $status);
    if ($status !== 0) {
        $failures++;
        echo implode(PHP_EOL, $output) . PHP_EOL;
    }
    $output = [];
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " PHP file(s) failed lint.\n");
    exit(1);
}

echo count($files) . " PHP files passed syntax lint.\n";

