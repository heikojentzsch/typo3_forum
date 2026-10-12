<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Security;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

final class MutationGuard
{
    public const SCOPE = 'typo3_forum/mutation';

    private const ACTIONS = [
        'Forum' => ['markRead'],
        'Post' => ['support', 'unsupport', 'create', 'update', 'delete'],
        'Topic' => ['create', 'solution', 'removeSolution'],
        'User' => ['subscribe'],
        'Tag' => ['create'],
        'Report' => ['createUserReport', 'createPostReport'],
        'Moderation' => ['createUserReportComment', 'createPostReportComment',
            'updateUserReportStatus', 'updatePostReportStatus', 'updateTopic', 'deleteTopic'],
    ];

    public function __construct(private readonly Context $context)
    {
    }

    public function check(RequestInterface $request): ?ResponseInterface
    {
        $controller = $request->getControllerName();
        $action = $request->getControllerActionName();
        if (!in_array($action, self::ACTIONS[$controller] ?? [], true)) {
            return null;
        }
        // Existing email links open a confirmation form, never change a subscription.
        if ($controller === 'User' && $action === 'subscribe' && $request->getMethod() === 'GET') {
            return null;
        }
        if ($request->getMethod() !== 'POST') {
            return new HtmlResponse('This action requires POST.', 405, ['Allow' => 'POST', 'Cache-Control' => 'no-store']);
        }
        // Core middleware already verifies the JWT against this browser's nonce cookie.
        $token = SecurityAspect::provideIn($this->context)->getReceivedRequestToken();
        if (!$token instanceof RequestToken || $token->scope !== self::SCOPE) {
            return new HtmlResponse('Invalid request token. Reload the page and try again.', 403, ['Cache-Control' => 'no-store']);
        }
        return null;
    }
}
