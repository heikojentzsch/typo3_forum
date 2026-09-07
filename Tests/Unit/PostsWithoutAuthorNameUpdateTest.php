<?php
declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Updates\PostsWithoutAuthorNameUpdate;

final class PostsWithoutAuthorNameUpdateTest extends DatabaseTestCase
{
    public function testMigrationOnlyChangesShortAnonymousNamesAndIsRepeatable(): void
    {
        $table = 'tx_typo3forum_domain_model_forum_post';
        $this->connection->executeStatement("CREATE TABLE $table (uid INTEGER PRIMARY KEY, author INTEGER, author_name TEXT)");
        foreach (['', 'A', 'AB', 'ABC', 'Ä', 'éé'] as $index => $name) {
            $this->connection->insert($table, ['uid' => $index + 1, 'author' => 0, 'author_name' => $name]);
        }
        $this->connection->insert($table, ['uid' => 7, 'author' => 42, 'author_name' => '']);
        $wizard = new PostsWithoutAuthorNameUpdate($this->pool);
        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());
        self::assertFalse($wizard->updateNecessary());
        self::assertSame(['Anonymous', 'Anonymous: A', 'Anonymous: AB', 'ABC', 'Anonymous: Ä', 'Anonymous: éé', ''],
            $this->connection->executeQuery("SELECT author_name FROM $table ORDER BY uid")->fetchFirstColumn());
        self::assertTrue($wizard->executeUpdate());
    }
}
