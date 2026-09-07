<?php
declare(strict_types=1);
namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Domain\Model\Forum\{Forum, Topic};
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Repository\Forum\TopicRepository;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Extbase\Persistence\Generic\Query;
use TYPO3\CMS\Extbase\Persistence\{QueryInterface, QueryResultInterface};

final class UnreadTopicsTest extends DatabaseTestCase
{
    public function testUnreadJoinAndExtbaseEntityReturnContract(): void
    {
        $table = 'tx_typo3forum_domain_model_forum_topic';
        $this->connection->executeStatement("CREATE TABLE $table (uid INTEGER PRIMARY KEY, forum INTEGER, title TEXT, pid INTEGER, deleted INTEGER, hidden INTEGER, sys_language_uid INTEGER)");
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_domain_model_user_readtopic (uid_local INTEGER, uid_foreign INTEGER)');
        foreach ([[1, 7, 'read'], [2, 7, 'other reader'], [3, 8, 'other forum'], [4, 7, 'unread']] as [$uid, $forum, $title]) {
            $this->connection->insert($table, ['uid' => $uid, 'forum' => $forum, 'title' => $title, 'pid' => 99, 'deleted' => 1, 'hidden' => 1, 'sys_language_uid' => 2]);
        }
        $this->connection->insert('tx_typo3forum_domain_model_user_readtopic', ['uid_local' => 10, 'uid_foreign' => 1]);
        $this->connection->insert('tx_typo3forum_domain_model_user_readtopic', ['uid_local' => 11, 'uid_foreign' => 2]);
        $forum = $this->createStub(Forum::class);
        $forum->method('getUid')->willReturn(7);
        $user = $this->createStub(FrontendUser::class);
        $user->method('getUid')->willReturn(10);
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('statement')->willReturnCallback(function (QueryBuilder $builder) use ($query) {
            $rows = $builder->executeQuery()->fetchAllAssociative();
            self::assertSame([2, 4], array_column($rows, 'uid'));
            self::assertSame(['other reader', 'unread'], array_column($rows, 'title'));
            self::assertSame([10, 7], array_values($builder->getParameters()));
            return $query;
        });
        $topics = [$this->createStub(Topic::class), $this->createStub(Topic::class)];
        $result = $this->createStub(QueryResultInterface::class);
        $result->method('toArray')->willReturn($topics);
        $query->expects(self::once())->method('execute')->willReturn($result);
        $repository = $this->getMockBuilder(TopicRepository::class)->onlyMethods(['createQuery'])->getMock();
        $repository->method('createQuery')->willReturn($query);
        $repository->injectConnectionPool($this->pool);
        self::assertSame($topics, $repository->getUnreadTopics($forum, $user));
    }
}
