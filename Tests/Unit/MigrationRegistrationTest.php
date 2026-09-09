<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Updates\ForumPluginMigrationUpdate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Upgrades\AbstractListTypeToCTypeUpdate;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;

final class MigrationRegistrationTest extends TestCase
{
    public function testWizardUsesThePublicTypo3V14ApiAndIsDiscoverable(): void
    {
        self::assertTrue(is_subclass_of(ForumPluginMigrationUpdate::class, AbstractListTypeToCTypeUpdate::class));
        $attributes = (new ReflectionClass(ForumPluginMigrationUpdate::class))->getAttributes(UpgradeWizard::class);
        self::assertCount(1, $attributes);
        self::assertSame('typo3ForumVerifiedPluginMigration', $attributes[0]->newInstance()->identifier);
        $wizard = (new ReflectionClass(ForumPluginMigrationUpdate::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ForumPluginMigrationUpdate::class, $wizard);
        self::assertSame([DatabaseUpdatedPrerequisite::class], $wizard->getPrerequisites());
    }

    public function testAllMigrationCommandsAreRegistered(): void
    {
        $services = (string)file_get_contents(dirname(__DIR__, 2) . '/Configuration/Services.yaml');
        foreach (['forum:migration:check', 'forum:migration:plan', 'forum:migration:apply', 'forum:migration:verify'] as $command) {
            self::assertStringContainsString("command: '$command'", $services);
        }
    }
}
