<?php

declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Controller\AbstractController;
use Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository;
use Mittwald\Typo3Forum\Service\Authentication\AuthenticationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;

abstract class AbstractControllerTestCase extends TestCase
{
    protected AuthenticationServiceInterface&MockObject $authenticationService;
    protected FrontendUserRepository&MockObject $frontendUserRepository;
    protected ViewInterface&MockObject $view;
    protected UriBuilder&MockObject $uriBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticationService = $this->createMock(AuthenticationServiceInterface::class);
        $this->frontendUserRepository = $this->createMock(FrontendUserRepository::class);
        $this->view = $this->createMock(ViewInterface::class);
        $this->uriBuilder = $this->createMock(UriBuilder::class);
    }

    protected function initializeController(AbstractController $controller): void
    {
        $controller->injectAuthenticationService($this->authenticationService);
        $controller->injectFrontendUserRepository($this->frontendUserRepository);
        $controller->injectResponseFactory(new ResponseFactory());
        $controller->injectStreamFactory(new StreamFactory());
        // These two properties have no public injection method in ActionController.
        $this->setProperty($controller, 'view', $this->view);
        $this->setProperty($controller, 'uriBuilder', $this->uriBuilder);
    }

    protected function setProperty(object $object, string $name, mixed $value): void
    {
        (new \ReflectionProperty($object, $name))->setValue($object, $value);
    }
}
