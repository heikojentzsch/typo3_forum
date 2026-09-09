<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TYPO3 Forum DDEV Provisioner',
    'description' => 'Development-only fixture provisioning.',
    'category' => 'misc',
    'state' => 'stable',
    'author' => 'Agentur Pottkinder',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
