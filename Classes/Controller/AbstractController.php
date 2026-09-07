<?php

namespace Mittwald\Typo3Forum\Controller;

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

use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository;
use Mittwald\Typo3Forum\Service\Authentication\AuthenticationServiceInterface;
use Mittwald\Typo3Forum\Utility\Localization;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Service\CacheService;
use TYPO3\CMS\Frontend\Page\PageInformation;

abstract class AbstractController extends ActionController
{
    const CONTEXT_WEB = 0;
    const CONTEXT_AJAX = 1;
    const CONTEXT_CLI = 2;

    /**
     * An authentication service. Handles the authentication mechanism.
     */
    protected AuthenticationServiceInterface $authenticationService;

    /**
     * The non-namespaced class name of this controller (e.g. ForumController
     * instead of \Mittwald\Typo3Forum\Controller\ForumController).
     */
    protected string $className;

    protected FrontendUserRepository $frontendUserRepository;
    protected CacheService $cacheService;

    /**
     * The current controller context. This context is necessary to enable
     * different behaviour of this controller e.g. in web/ajax/cli context.
     */
    protected int $context = self::CONTEXT_WEB;

    public function injectFrontendUserRepository(
        FrontendUserRepository $frontendUserRepository
    ): void {
        $this->frontendUserRepository = $frontendUserRepository;
    }

    public function injectAuthenticationService(
        AuthenticationServiceInterface $authenticationService
    ): void {
        $this->authenticationService = $authenticationService;
    }

    public function injectCacheService(CacheService $cacheService): void
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Gets the currently logged in frontend user.
     *
     * @return FrontendUser The frontend user that is currently logged in.
     */
    protected function getCurrentUser()
    {
        return $this->frontendUserRepository->findCurrent();
    }

    /**
     * Disable default error flash messages.
     */
    protected function getErrorFlashMessage(): string|bool
    {
        return false;
    }

    /**
     * Clears the cache for the current page.
     */
    protected function clearCacheForCurrentPage(): void
    {
        $pageInformation = $this->request->getAttribute('frontend.page.information');

        if (!$pageInformation instanceof PageInformation) {
            throw new \RuntimeException(
                'Current frontend page information is not available.',
                1788746404
            );
        }

        $this->cacheService->clearPageCache($pageInformation->getId());
    }

    /**
     * Adds a localized message to the flash message container.
     *
     * @param string $key
     * @param array $arguments
     * @param string|null $titleKey
     * @param int $severity
     */
    protected function addLocalizedFlashmessage(
        $key,
        array $arguments = [],
        $titleKey = null,
        $severity = ContextualFeedbackSeverity::OK
    ) {
        $message = new FlashMessage(
            Localization::translate($key, 'Typo3Forum', $arguments),
            Localization::translate($titleKey, 'Typo3Forum'),
            $severity
        );

        $this->getFlashMessageQueue()->enqueue($message);
    }

    /**
     * @param string $actionName
     * @param string|null $controllerName
     * @param string|null $extensionName
     * @param array|null $arguments
     * @param int|null $pageUid
     * @param int $delay
     * @param int $statusCode
     */
    protected function redirect(
        $actionName,
        $controllerName = null,
        $extensionName = null,
        array $arguments = null,
        $pageUid = null,
        $delay = 0,
        $statusCode = 303
    ): ResponseInterface {
        if ($this->context === self::CONTEXT_WEB && $this->request->getFormat() === 'html') {
            parent::redirect(
                $actionName,
                $controllerName,
                $extensionName,
                $arguments,
                $pageUid,
                $delay,
                $statusCode
            );
        }

        return $this->htmlResponse();
    }

    public function setContext(int $context): void
    {
        $this->context = $context;
    }

    /**
     * @param string $url
     * @return mixed
     */
    public function purgeUrl($url)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PURGE');
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Host:' . $_SERVER['HTTP_HOST']]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($curl);

        return $result;
    }

    /**
     * Returns a redirect response if a referrer was found, otherwise false.
     */
    protected function redirectToReferrer(): ResponseInterface|bool
    {
        $referrerUri = $this->request->getServerParams()['HTTP_REFERER'] ?? '';

        if ($referrerUri === '') {
            $referrerUri = $this->request->getHeader('referer')[0] ?? '';
        }

        if ($referrerUri === '') {
            return false;
        }

        return $this->redirectToUri($referrerUri);
    }
}