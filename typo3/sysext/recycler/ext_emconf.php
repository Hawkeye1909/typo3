<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TYPO3 CMS Recycler',
    'description' => 'Restore deleted records or remove them from the database permanently.',
    'category' => 'module',
    'author' => 'TYPO3 Core Team',
    'author_email' => 'typo3cms@typo3.org',
    'state' => 'stable',
    'author_company' => '',
    'version' => '12.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.1.0',
        ],
        'conflicts' => [],
        'suggests' => [
            'scheduler' => '',
        ],
    ],
];
