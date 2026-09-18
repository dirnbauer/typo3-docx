<?php

declare(strict_types=1);

use TYPO3\CodingStandards\CsFixerConfig;

$config = CsFixerConfig::create();
$config->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache');
$config->getFinder()
    ->in([
        __DIR__ . '/Classes',
        __DIR__ . '/Configuration',
        __DIR__ . '/Tests',
    ])
    ->append([__FILE__]);

return $config;
