<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NotificationForumConfigurationTest extends TestCase
{
    private const FIELDS = [
        'notification_include_forum_name_in_subject',
        'notification_include_post_text',
        'notification_include_forum_link',
        'notification_include_topic_link',
        'notification_include_unsubscribe_link',
    ];

    public function testSchemaAddsOnlyInheritDefaults(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/ext_tables.sql');
        self::assertIsString($schema);
        foreach (self::FIELDS as $field) {
            self::assertMatchesRegularExpression(
                '/^\s*' . preg_quote($field, '/') . " tinyint\(1\) NOT NULL default '0',$/m",
                $schema,
                $field
            );
        }
    }

    public function testTcaProvidesAllThreeStatesForEveryOverride(): void
    {
        $tca = require dirname(__DIR__, 2) . '/Configuration/TCA/tx_typo3forum_domain_model_forum_forum.php';
        self::assertStringContainsString('notification_section', $tca['types']['1']['showitem']);
        foreach (self::FIELDS as $field) {
            $column = $tca['columns'][$field];
            self::assertSame('select', $column['config']['type']);
            self::assertSame('selectSingle', $column['config']['renderType']);
            self::assertSame(0, $column['config']['default']);
            self::assertSame([0, 1, 2], array_column($column['config']['items'], 1));
            self::assertStringContainsString($field, $column['label']);
            self::assertStringContainsString($field . '.description', $column['description']);
        }
    }

    public function testEnglishAndGermanBackendLabelsAreValidAndComplete(): void
    {
        foreach (['locallang_db.xlf', 'de.locallang_db.xlf'] as $file) {
            $xml = simplexml_load_file(dirname(__DIR__, 2) . '/Resources/Private/Language/' . $file);
            self::assertInstanceOf(\SimpleXMLElement::class, $xml, $file);
            $ids = [];
            foreach ($xml->xpath('//*[local-name()="trans-unit"]') ?: [] as $unit) {
                $ids[] = (string)$unit['id'];
            }
            foreach (self::FIELDS as $field) {
                $key = 'tx_typo3forum_domain_model_forum_forum.' . $field;
                self::assertContains($key, $ids, $file);
                self::assertContains($key . '.description', $ids, $file);
            }
            foreach (['notification_override.inherit', 'notification_override.enabled', 'notification_override.disabled'] as $key) {
                self::assertContains($key, $ids, $file);
            }
        }
    }
}
