<?php
/*                                                                      *
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

namespace Mittwald\Typo3Forum\Updates;

use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\RepeatableInterface;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;

#[UpgradeWizard('typo3ForumPostsWithoutAuthorName')]
class PostsWithoutAuthorNameUpdate implements UpgradeWizardInterface, RepeatableInterface
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {}

    public function getTitle(): string
    {
        return '[typo3_forum]: Migrate anonymous posts to have a valid author_name';
    }

    public function getDescription(): string
    {
        return 'Set Anonymous for empty anonymous author names and prepend Anonymous: to names shorter than three characters.';
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }

    public function executeUpdate(): bool
    {
        $table = 'tx_typo3forum_domain_model_forum_post';
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->update($table)
            ->where(
                $queryBuilder->expr()->eq('author_name', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('author', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->set('author_name', 'Anonymous')
            ->executeStatement();

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'author_name')->from($table)
            ->where(
                $queryBuilder->expr()->comparison($queryBuilder->expr()->length('author_name'), ExpressionBuilder::LT, $queryBuilder->createNamedParameter(3, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('author_name', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('author', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )->executeQuery();
        $connection = $this->connectionPool->getConnectionForTable($table);
        foreach ($result->fetchAllAssociative() as $post) {
            $connection->update($table, ['author_name' => 'Anonymous: ' . $post['author_name']], ['uid' => (int)$post['uid']], [Connection::PARAM_STR, Connection::PARAM_INT]);
        }
        return !$this->updateNecessary();
    }

    public function updateNecessary(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_typo3forum_domain_model_forum_post');
        $queryBuilder->getRestrictions()->removeAll();
        return (int)$queryBuilder->count('*')->from('tx_typo3forum_domain_model_forum_post')
            ->where(
                $queryBuilder->expr()->comparison($queryBuilder->expr()->length('author_name'), ExpressionBuilder::LT, $queryBuilder->createNamedParameter(3, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('author', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )->executeQuery()->fetchOne() > 0;
    }
}
