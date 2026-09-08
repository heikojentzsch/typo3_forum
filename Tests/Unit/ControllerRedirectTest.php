<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Controller\AbstractController;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

final class ControllerRedirectTest extends AbstractControllerTestCase
{
    public function testHtmlRequestsStillUseCoreRedirect(): void
    {
        $controller = $this->controller('html');
        $this->uriBuilder->method('reset')->willReturnSelf();
        $this->uriBuilder->method('setCreateAbsoluteUri')->willReturnSelf();
        $this->uriBuilder->expects(self::once())->method('uriFor')
            ->with('show', null, 'Forum', null)->willReturn('https://example.test/forum');
        $this->view->expects(self::never())->method('render');
        $response = $controller->go();
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://example.test/forum', $response->getHeaderLine('Location'));
    }

    public function testNonHtmlRequestsStillRenderInsteadOfRedirecting(): void
    {
        $controller = $this->controller('json');
        $this->uriBuilder->expects(self::never())->method('uriFor');
        $this->view->expects(self::once())->method('render')->willReturn('body');
        $response = $controller->go();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('body', (string)$response->getBody());
    }

    private function controller(string $format): AbstractController
    {
        $controller = new class () extends AbstractController {
            public function go(): ResponseInterface
            {
                return $this->redirect('show', 'Forum');
            }
        };
        $this->initializeController($controller);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getFormat')->willReturn($format);
        $params = $this->createStub(NormalizedParams::class);
        $params->method('isHttps')->willReturn(false);
        $request->method('getAttribute')->with('normalizedParams')->willReturn($params);
        $this->setProperty($controller, 'request', $request);
        return $controller;
    }
}
