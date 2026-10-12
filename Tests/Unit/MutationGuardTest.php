<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Security\MutationGuard;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

final class MutationGuardTest extends TestCase
{
    public function testMutationRequiresPostAndValidScopedToken(): void
    {
        $context = new Context();
        $security = SecurityAspect::provideIn($context);
        $guard = new MutationGuard($context);
        foreach (['support', 'unsupport', 'create', 'update', 'delete'] as $action) {
            $request = $this->request('Post', $action, 'GET');
            self::assertSame(405, $guard->check($request)?->getStatusCode());
            $request = $this->request('Post', $action, 'POST');
            foreach ([null, false, RequestToken::create('unrelated')] as $token) {
                $security->setReceivedRequestToken($token);
                self::assertSame(403, $guard->check($request)?->getStatusCode());
            }
            $security->setReceivedRequestToken(RequestToken::create(MutationGuard::SCOPE));
            self::assertNull($guard->check($request));
        }
    }

    public function testSubscriptionGetCanRenderConfirmationButPostNeedsToken(): void
    {
        $guard = new MutationGuard(new Context());
        self::assertNull($guard->check($this->request('User', 'subscribe', 'GET')));
        self::assertSame(403, $guard->check($this->request('User', 'subscribe', 'POST'))?->getStatusCode());
        self::assertSame(405, $guard->check($this->request('User', 'subscribe', 'DELETE'))?->getStatusCode());
        self::assertNull($guard->check($this->request('Topic', 'show', 'GET')));
    }

    private function request(string $controller, string $action, string $method): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getControllerName')->willReturn($controller);
        $request->method('getControllerActionName')->willReturn($action);
        $request->method('getMethod')->willReturn($method);
        return $request;
    }
}
