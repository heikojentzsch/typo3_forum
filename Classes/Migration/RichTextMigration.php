<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Migration;

use Mittwald\Typo3Forum\RichText\LegacyConverter;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RichTextMigration
{
    public const FIELDS = [
        'tx_typo3forum_domain_model_forum_post' => ['text', 'text_html'],
        'tx_typo3forum_domain_model_moderation_reportcomment' => ['text', 'text_html'],
        'fe_users' => ['tx_typo3forum_signature', 'tx_typo3forum_signature_html'],
    ];

    public function __construct(private readonly ConnectionPool $pool)
    {
    }

    /** @param array<string, array<string, string>> $schema
     *  @param list<array<string, mixed>> $findings
     *  @return list<array<string, mixed>>
     */
    public function inventory(array $schema, array &$findings): array
    {
        $records = [];
        foreach (self::FIELDS as $table => [$source, $target]) {
            if (!isset($schema[$table][$source])) {
                continue;
            }
            if (!isset($schema[$table][$target])) {
                $findings[] = ['severity' => 'blocker', 'code' => 'RICH_TEXT_SCHEMA_REQUIRED', 'table' => $table, 'column' => $target];
                continue;
            }
            $connection = $this->pool->getConnectionForTable($table);
            $sql = 'SELECT uid, ' . $connection->quoteIdentifier($source) . ', ' . $connection->quoteIdentifier($target) . ' FROM ' . $connection->quoteIdentifier($table) . ' WHERE ' . $connection->quoteIdentifier($target) . ' IS NULL ORDER BY uid';
            foreach ($connection->executeQuery($sql)->iterateAssociative() as $row) {
                $row['uid'] = (int)$row['uid'];
                $converted = (new LegacyConverter())->convert((string)$row[$source]);
                foreach ($converted['warnings'] as $warning) {
                    $findings[] = ['severity' => 'blocker', 'code' => 'BBCODE_REVIEW_REQUIRED', 'table' => $table, 'uid' => $row['uid'], 'message' => $warning];
                }
                $records[] = ['table' => $table, 'uid' => $row['uid'], 'field' => $target, 'source' => $row, 'html' => $converted['html']];
            }
        }
        if ($records !== [] && isset($schema['tx_typo3forum_domain_model_format_textparser']['groups'])) {
            $connection = $this->pool->getConnectionForTable('tx_typo3forum_domain_model_format_textparser');
            $fields = ['uid', 'groups', 'bbcode_wrap', 'alias', 'type', 'regular_expression', 'regular_expression_replacement'];
            foreach ($connection->executeQuery('SELECT ' . implode(', ', array_map($connection->quoteIdentifier(...), $fields)) . ' FROM tx_typo3forum_domain_model_format_textparser')->iterateAssociative() as $definition) {
                $reason = null;
                if (trim((string)$definition['groups']) !== '') {
                    $reason = 'Group-restricted legacy formatting requires an explicit migration rule.';
                } elseif (str_ends_with(strtolower((string)$definition['type']), 'smiley') && !isset(LegacyConverter::SMILEYS[(string)$definition['alias']])) {
                    $reason = 'Custom smiley requires an explicit Unicode mapping.';
                } elseif (preg_match('/\[([a-z]+)\]/i', (string)$definition['bbcode_wrap'], $match) && !in_array(strtolower($match[1]), ['b', 'i', 'u', 's', 'quote', 'list', 'url', 'img', 'code'], true)) {
                    $reason = 'Custom BBCode requires an explicit migration rule.';
                }
                if ((string)$definition['regular_expression'] !== '') {
                    $standard = [
                        '[b]|[/b]' => ['/\\[b\\](.*)\\[\\/b\\]/iU', '<b>\\1</b>'],
                        '[i]|[/i]' => ['/\\[i\\](.*)\\[\\/i\\]/iU', '<i>\\1</i>'],
                        '[u]|[/u]' => ['/\\[u\\](.*)\\[\\/u\\]/iU', '<u>\\1</u>'],
                        '[img]|[/img]' => ['/\\[img\\](.*)\\[\\/img\\]/iU', '<img src="\\1" />'],
                    ];
                    if (($standard[(string)$definition['bbcode_wrap']] ?? null) !== [$definition['regular_expression'], $definition['regular_expression_replacement']]) {
                        $reason = 'Customized database parser expression requires review; expressions are never executed by the converter.';
                    }
                }
                if ($reason !== null) {
                    $findings[] = ['severity' => 'blocker', 'code' => 'LEGACY_DEFINITION_REVIEW_REQUIRED', 'uid' => (int)$definition['uid'], 'message' => $reason];
                }
            }
        }
        return $records;
    }

    /** @param array<string, mixed> $operation */
    public function validate(array $operation): void
    {
        [$source, $target] = self::FIELDS[$operation['table']];
        $before = $operation['before'];
        $after = $operation['after'];
        $converted = (new LegacyConverter())->convert((string)($before[$source] ?? ''));
        if ($operation['rule_id'] !== 'bbcode-to-html-v1' || $operation['fields'] !== [$target]
            || array_keys($before) !== ['uid', $source, $target] || array_keys($after) !== array_keys($before)
            || $before[$target] !== null || $after[$source] !== $before[$source]
            || $after[$target] !== $converted['html'] || $converted['warnings'] !== []) {
            throw new \RuntimeException('The rich-text operation does not match the deterministic conversion rule.');
        }
    }
}
