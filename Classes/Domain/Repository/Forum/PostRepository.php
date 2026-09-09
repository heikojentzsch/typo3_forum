<?php
namespace Mittwald\Typo3Forum\Domain\Repository\Forum;

/*                                                                    - *
 *  COPYRIGHT NOTICE                                                    *
 *                                                                      *
 *  (c) 2015 Mittwald CM Service GmbH & Co KG                           *
 *           All rights reserved                                        *
 *                                                                      *
 *  This script is part of the TYPO3 project. The TYPO3 project is      *
 *  free software; you can redistribute it and/or modify                *
 *  it under the terms of the GNU General Public License as published   *
 *  by the Free Software Foundation; either version 2 of the License,   *
 *  or (at your option) any later version.                              *
 *                                                                      *
 *  The GNU General Public License can be found at                      *
 *  http://www.gnu.org/copyleft/gpl.html.                               *
 *                                                                      *
 *  This script is distributed in the hope that it will be useful,      *
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of      *
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the       *
 *  GNU General Public License for more details.                        *
 *                                                                      *
 *  This copyright notice MUST APPEAR in all copies of the script!      *
 *                                                                      */

use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Repository\AbstractRepository;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/** @extends AbstractRepository<\Mittwald\Typo3Forum\Domain\Model\Forum\Post> */
class PostRepository extends AbstractRepository
{
    /**
     * @return QueryResultInterface<int, Post>
     */
    public function findByAuthor(FrontendUser $user): QueryResultInterface
    {
        $query = $this->createQuery();

        return $query->matching($query->equals('author', $user))->execute();
    }

    /**
     * Finds posts for a specific filterset. Page navigation is possible.
     *
     * @param int $limit
     * @param array   $orderings
     *
     * @return QueryResultInterface<int, Post>
     *                               The selected subset of posts
     * @phpstan-param array<string, string>|null $orderings
     */
    public function findByFilter(?int $limit = null, ?array $orderings = null): QueryResultInterface
    {
        $query = $this->createQuery();
        if ($limit !== null) {
            $query->setLimit($limit);
        }
        if ($orderings !== null) {
            $query->setOrderings($orderings);
        }

        return $query->execute();
    }

    /**
     * Finds posts for a specific filterset. Page navigation is possible.
     *
     * @param array $uids
     *
     * @return QueryResultInterface<int, Post> The selected subset of posts
     * @phpstan-param list<int|string> $uids
     */
    public function findByUids(array $uids): QueryResultInterface
    {
        $query = $this->createQuery();
        $constraints = [];
        if (!empty($uids)) {
            $constraints[] = $query->in('uid', $uids);
        }
        if (!empty($constraints)) {
            $query->matching($query->logicalAnd(...$constraints));
        }

        return $query->execute();
    }

    /**
     * Finds posts for a specific topic. Page navigation is possible.
     *
     * @param \Mittwald\Typo3Forum\Domain\Model\Forum\Topic $topic
     *                               The topic for which the posts are to be loaded.
     *
     * @return QueryResultInterface<int, Post>
     *                               The selected subset of posts in the specified
     *                               topic.
     */
    public function findForTopic(\Mittwald\Typo3Forum\Domain\Model\Forum\Topic $topic): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectSysLanguage(false);

        return $query->matching($query->equals('topic', $topic))
            ->setOrderings(['crdate' => \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_ASCENDING])
            ->setLimit(1000000)
            ->execute();
    }

    /**
     * Finds the last post in a topic.
     *
     * @param \Mittwald\Typo3Forum\Domain\Model\Forum\Topic $topic
     *                                The topic for which the last post is to be
     *                                loaded.
     * @param int                                            $offset
     *                                If you want to get the next to last post post
     *
     * @return Post|null
     *                             The last post of the specified topic.
     */
    public function findLastByTopic(\Mittwald\Typo3Forum\Domain\Model\Forum\Topic $topic, int $offset = 0): ?Post
    {
        $query = $this->createQuery();
        $query->matching($query->equals('topic', $topic))
            ->setOrderings(['crdate' => \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_DESCENDING])->setLimit(1);
        if ($offset > 0) {
            $query->setOffset($offset);
        }

        return $query->execute()->getFirst();
    }

    /**
     * Finds the last post in a forum.
     *
     * @param \Mittwald\Typo3Forum\Domain\Model\Forum\Forum $forum
     *                                The forum for which to load the last post.
     * @param int                                            $offset
     *                                If you want to get the next to last post post
     *
     * @return Post|null
     *                             The last post of the specified forum.
     */
    public function findLastByForum(\Mittwald\Typo3Forum\Domain\Model\Forum\Forum $forum, int $offset = 0): ?Post
    {
        $query = $this->createQuery();
        $query->matching($query->equals('topic.forum', $forum))
            ->setOrderings(['crdate' => \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_DESCENDING])->setLimit(1);
        if ($offset > 0) {
            $query->setOffset($offset);
        }

        return $query->execute()->getFirst();
    }
}
