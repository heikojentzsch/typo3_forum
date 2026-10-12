<?php

declare(strict_types = 1);

foreach (\Mittwald\Typo3Forum\Migration\RichTextMigration::FIELDS as $table => [$source, $target]) {
    $GLOBALS['TCA'][$table]['columns'][$target] = [
        'label' => 'Forum HTML (v1)',
        'config' => ['type' => 'text', 'rows' => 12, 'nullable' => true, 'default' => null],
    ];
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes($table, $target, '', 'after:' . $source);
}
