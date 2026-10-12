<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\RichText;

use Mittwald\Typo3Forum\Migration\RichTextMigration;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** Backend/import writes obey the same HTML rules as frontend writes. */
final class DataHandlerHook
{
    /** @param array<string, mixed> $fields */
    public function processDatamap_preProcessFieldArray(array &$fields, string $table, string|int $id, DataHandler $handler): void
    {
        if (!isset(RichTextMigration::FIELDS[$table])) {
            return;
        }
        [$source, $target] = RichTextMigration::FIELDS[$table];
        if (array_key_exists($source, $fields) || array_key_exists($target, $fields)) {
            GeneralUtility::makeInstance(CharsetRequirement::class)->assertReady();
        }
        if (isset($fields[$target])) {
            $html = new Html();
            $fields[$target] = $html->sanitize((string)$fields[$target]);
            $fields[$source] = $html->plainText($fields[$target]);
        } elseif (array_key_exists($source, $fields)) {
            $fields[$target] = (new LegacyConverter())->convert((string)$fields[$source])['html'];
        }
    }
}
