<?php

defined('TYPO3') || die();

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

(function (): void {
    ExtensionUtility::registerPlugin(
        'typo3_forum',
        'Forum',
        'Forum',
        null,
        'TYPO3 Forum'
    );

    ExtensionUtility::registerPlugin(
        'typo3_forum',
        'UserProfile',
        'LLL:EXT:typo3_forum/Resources/Private/Language/locallang_flexforms.xlf:Behaviour_Action_User_Show',
        null,
        'TYPO3 Forum'
    );

    ExtensionUtility::registerPlugin(
        'typo3_forum',
        'ModerationReports',
        'LLL:EXT:typo3_forum/Resources/Private/Language/locallang_flexforms.xlf:Behaviour_Action_Moderation_Reports',
        null,
        'TYPO3 Forum'
    );

    ExtensionUtility::registerPlugin(
        'typo3_forum',
        'UserList',
        'LLL:EXT:typo3_forum/Resources/Private/Language/locallang_flexforms.xlf:Behaviour_Action_Users_List',
        null,
        'TYPO3 Forum',
        '',
        'FILE:EXT:typo3_forum/Configuration/FlexForms/UserList.xml'
    );

    ExtensionUtility::registerPlugin(
        'typo3_forum',
        'Dashboard',
        'LLL:EXT:typo3_forum/Resources/Private/Language/locallang_flexforms.xlf:Behaviour_Action_Dashboard',
        null,
        'TYPO3 Forum'
    );

    ExtensionUtility::registerPlugin(
        'typo3_forum',
        'TagList',
        'LLL:EXT:typo3_forum/Resources/Private/Language/locallang_flexforms.xlf:Behaviour_Action_Tags',
        null,
        'TYPO3 Forum'
    );

    ExtensionUtility::registerPlugin(
        'typo3_forum',
        'PostList',
        'LLL:EXT:typo3_forum/Resources/Private/Language/locallang_flexforms.xlf:Behaviour_Action_Posts_List',
        null,
        'TYPO3 Forum',
        '',
        'FILE:EXT:typo3_forum/Configuration/FlexForms/PostList.xml'
    );

    ExtensionUtility::registerPlugin(
        'typo3_forum',
        'TopicList',
        'LLL:EXT:typo3_forum/Resources/Private/Language/locallang_flexforms.xlf:Behaviour_Action_Topics_List',
        null,
        'TYPO3 Forum',
        '',
        'FILE:EXT:typo3_forum/Configuration/FlexForms/TopicList.xml'
    );

    ExtensionUtility::registerPlugin(
        'typo3_forum',
        'StatsBox',
        'LLL:EXT:typo3_forum/Resources/Private/Language/locallang_flexforms.xlf:Behaviour_Widget_Stats_Box',
        null,
        'TYPO3 Forum'
    );
})();