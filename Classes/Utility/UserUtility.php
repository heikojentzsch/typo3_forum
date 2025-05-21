<?php

namespace Mittwald\Typo3Forum\Utility;

use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class UserUtility
{

    public static function isUserLoggedIn(): bool
    {
        return (bool) self::getLoggedInUserId();
    }

    public static function getLoggedInUserId(): int
    {
        $context = GeneralUtility::makeInstance(Context::class);
        return $context->getPropertyFromAspect('frontend.user', 'id');
    }

    public function getLoggedInUser()
    {
        /**
         * @var FrontendUserRepository $frontendUserRepository
         */
        $userId = self::getLoggedInUserId();
        $frontendUserRepository = GeneralUtility::makeInstance(FrontendUserRepository::class);

        return $frontendUserRepository->findByUid($userId);
    }

}
