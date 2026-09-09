<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'typo3_forum',
    'description' => 'Forum extension',
    'category' => 'plugin',
    'author' => 'Agentur Pottkinder',
    'author_email' => 'support@agentur-pottkinder.de',
    'author_company' => 'Agentur Pottkinder',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => 'typo3temp/typo3_forum,typo3temp/typo3_forum/gravatar, typo3temp/typo3_forum/workflowstatus',
    'modify_tables' => 'fe_users',
    'clearCacheOnLoad' => 0,

    // Keep this fallback metadata in sync before tagging. Release builds never rewrite it.
    // Composer derives the authoritative release version from the Git tag.
    'version' => '14.0.0-dev',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ]
    ],
];
