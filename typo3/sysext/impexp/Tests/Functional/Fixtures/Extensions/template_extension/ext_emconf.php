<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'Impexp test extension',
    'description' => '',
    'category' => '',
    'version' => '12.1.0',
    'state' => 'beta',
    'clearCacheOnLoad' => 1,
    'author' => 'Marc Bastian Heinrichs',
    'author_email' => 'typo3@mbh-software.de',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '12.1.0',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
