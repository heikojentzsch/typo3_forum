<?php
declare(strict_types=1);
namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Domain\Model\Forum\Topic;
use Mittwald\Typo3Forum\Domain\Model\User\{FrontendUser, AnonymousFrontendUser};
use Mittwald\Typo3Forum\ViewHelpers\Form\ForumSelectViewHelper;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\Cache\SimpleFileCache;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\Core\ViewHelper\{ViewHelperInterface, ViewHelperResolver};
use TYPO3Fluid\Fluid\Validation\TemplateValidator;

final class FluidRenderingTest extends TestCase
{
    private mixed $previousContainer;
    private array $services = [];

    protected function setUp(): void
    {
        $this->previousContainer = (new \ReflectionProperty(\TYPO3\CMS\Core\Utility\GeneralUtility::class, 'container'))->getValue();
        $imageService = $this->createStub(\TYPO3\CMS\Extbase\Service\ImageService::class);
        $container = $this->createStub(\Psr\Container\ContainerInterface::class);
        $this->services[\TYPO3\CMS\Extbase\Service\ImageService::class] = $imageService;
        $container->method('has')->willReturnCallback(fn($id) => isset($this->services[$id]));
        $container->method('get')->willReturnCallback(fn($id) => $this->services[$id]);
        \TYPO3\CMS\Core\Utility\GeneralUtility::setContainer($container);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(\TYPO3\CMS\Core\Utility\GeneralUtility::class, 'container'))->setValue(null, $this->previousContainer);
    }

    private function context(): RenderingContext
    {
        $context = new RenderingContext();
        $factory = function (string $class): ViewHelperInterface {
            $reflection = new \ReflectionClass($class);
            $arguments = [];
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->getType() instanceof \ReflectionNamedType && !$parameter->getType()->isBuiltin()) {
                    if (isset($this->services[$parameter->getType()->getName()])) {
                        $arguments[] = $this->services[$parameter->getType()->getName()];
                        continue;
                    }
                    $dependency = new \ReflectionClass($parameter->getType()->getName());
                    $arguments[] = $dependency->isFinal()
                        ? $dependency->newInstanceWithoutConstructor()
                        : $this->createStub($dependency->getName());
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                } else {
                    $arguments[] = $this->createStub($parameter->getType()->getName());
                }
            }
            return $reflection->newInstanceArgs($arguments);
        };
        $resolver = new class($factory) extends ViewHelperResolver {
            public function __construct(private \Closure $factory) {}
            public function createViewHelperInstanceFromClassName($class): ViewHelperInterface
            {
                return ($this->factory)($class);
            }
        };
        $resolver->addNamespace('f', 'TYPO3Fluid\\Fluid\\ViewHelpers');
        $resolver->addNamespace('f', 'TYPO3\\CMS\\Fluid\\ViewHelpers');
        $resolver->addNamespace('mmf', 'Mittwald\\Typo3Forum\\ViewHelpers');
        $context->setViewHelperResolver($resolver);
        $cachePath = dirname(__DIR__, 2) . '/.Build/fluid-test-cache';
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }
        $context->setCache(new SimpleFileCache($cachePath));
        return $context;
    }

    public function testEveryShippedFluidFileParsesAndCompiles(): void
    {
        $context = $this->context();
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/Resources/Private')) as $file) {
            if ($file->getExtension() === 'html') {
                $files[] = $file->getPathname();
            }
        }
        self::assertNotEmpty($files);
        // Same validator used by TYPO3 fluid:analyze; constructor collaborators are isolated from site/DB state.
        foreach ((new TemplateValidator())->validateTemplateFiles($files, $context) as $result) {
            self::assertSame([], array_map(static fn($error) => $error->getMessage(), $result->errors), $result->path);
            self::assertSame([], $result->deprecations, $result->path);
            $id = 'audit_' . hash('sha256', $result->path . file_get_contents($result->path));
            $parsed = $context->getTemplateParser()->parse(file_get_contents($result->path), $id);
            $context->getTemplateCompiler()->store($id, $parsed);
            self::assertTrue($context->getTemplateCompiler()->has($id), $result->path);
            self::assertInstanceOf(\TYPO3Fluid\Fluid\Core\Compiler\AbstractCompiledTemplate::class, $context->getTemplateCompiler()->get($id), $result->path);
        }
    }

    private function renderBoth(string $source, array $variables, string $expected): void
    {
        $context = $this->context();
        foreach ($variables as $key => $value) {
            $context->getVariableProvider()->add($key, $value);
        }
        $id = 'behavior_' . hash('sha256', $source);
        $parsed = $context->getTemplateParser()->parse($source, $id);
        self::assertSame($expected, $parsed->render($context));
        $context->getTemplateCompiler()->store($id, $parsed);
        self::assertSame($expected, $context->getTemplateCompiler()->get($id)->render($context));
    }

    public function testSubscriptionConditionInParsedAndCompiledTemplates(): void
    {
        $user = $this->createStub(FrontendUser::class);
        $other = $this->createStub(FrontendUser::class);
        $topic = $this->createStub(Topic::class);
        $storage = new \TYPO3\CMS\Extbase\Persistence\ObjectStorage();
        $storage->attach($user);
        $topic->method('getSubscribers')->willReturn($storage);
        $source = '<mmf:user.ifSubscribed object="{topic}" user="{user}"><f:then>yes</f:then><f:else>no</f:else></mmf:user.ifSubscribed>';
        $this->renderBoth($source, ['topic' => $topic, 'user' => $user], 'yes');
        $this->renderBoth($source, ['topic' => $topic, 'user' => $other], 'no');
    }

    public function testSubscriptionUsesInjectedCurrentUserInParsedAndCompiledTemplates(): void
    {
        $user = $this->createStub(FrontendUser::class);
        $repository = $this->createMock(\Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository::class);
        $repository->expects(self::exactly(4))->method('findCurrent')->willReturn($user);
        $this->services[\Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository::class] = $repository;
        $topic = $this->createStub(Topic::class);
        $storage = new \TYPO3\CMS\Extbase\Persistence\ObjectStorage();
        $storage->attach($user);
        $topic->method('getSubscribers')->willReturn($storage);
        $source = '<mmf:user.ifSubscribed object="{topic}" then="yes" else="no" />';
        $this->renderBoth($source, ['topic' => $topic], 'yes');
        $storage->detach($user);
        $this->renderBoth($source, ['topic' => $topic], 'no');
    }

    public function testExplicitSubscriptionUserDoesNotQueryRepository(): void
    {
        $user = $this->createStub(FrontendUser::class);
        $repository = $this->createMock(\Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository::class);
        $repository->expects(self::never())->method('findCurrent');
        $this->services[\Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository::class] = $repository;
        $topic = $this->createStub(Topic::class);
        $storage = new \TYPO3\CMS\Extbase\Persistence\ObjectStorage();
        $storage->attach($user);
        $topic->method('getSubscribers')->willReturn($storage);
        $this->renderBoth('<mmf:user.ifSubscribed object="{topic}" user="{user}">yes</mmf:user.ifSubscribed>', ['topic' => $topic, 'user' => $user], 'yes');
    }

    public function testInstanceConditionInParsedAndCompiledTemplates(): void
    {
        $source = '<mmf:ifInstanceOf object="{object}" className="{class}"><f:then>yes</f:then><f:else>no</f:else></mmf:ifInstanceOf>';
        $topic = $this->createStub(Topic::class);
        $this->renderBoth($source, ['object' => $topic, 'class' => Topic::class], 'yes');
        $this->renderBoth($source, ['object' => $topic, 'class' => FrontendUser::class], 'no');
    }

    public function testPaginationAcceptsTraversableAndRestoresOuterVariables(): void
    {
        $this->renderBoth(
            '<mmf:pagination.paginate objects="{objects}" as="items" page="1" configuration="{itemsPerPage: 2}" configAs="pagination"><f:for each="{items}" as="item">{item}</f:for></mmf:pagination.paginate>|{items}|{pagination}',
            ['objects' => new \ArrayObject(['a', 'b', 'c']), 'items' => 'outer', 'pagination' => 'outerPager'],
            'ab|outer|outerPager'
        );
    }

    public function testAvatarPreservesDimensionsAndEscapesAttributes(): void
    {
        $user = $this->createStub(FrontendUser::class);
        $user->method('getImagePath')->willReturn('/avatar.png');
        $this->renderBoth('<mmf:user.avatar user="{user}" width="64" height="32" alt="{alt}" />', ['user' => $user, 'alt' => '<name>'], '<img width="64" height="32" alt="&lt;name&gt;" src="/avatar.png" />');
    }

    public function testAnonymousUserLinkIsEscaped(): void
    {
        $user = $this->createStub(AnonymousFrontendUser::class);
        $user->method('isAnonymous')->willReturn(true);
        $user->method('getUsername')->willReturn('<img src=x onerror=alert(1)>');
        $this->renderBoth('<mmf:user.link user="{user}" />', ['user' => $user], '&lt;img src=x onerror=alert(1)&gt;');
    }

    public function testFileSizeArgumentsAreRegistered(): void
    {
        $this->renderBoth('<mmf:format.fileSize decimals="1" decimalSeparator=".">1536</mmf:format.fileSize>', [], '1.5 KiB');
    }

    public function testAllPublicCustomViewHelpersRegisterArguments(): void
    {
        $context = $this->context();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/Classes/ViewHelpers')) as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', dirname(__DIR__, 2) . '/Classes/')));
            $class = 'Mittwald\\Typo3Forum\\' . str_replace('/', '\\', substr($relative, 0, -4));
            $helper = $context->getViewHelperResolver()->createViewHelperInstanceFromClassName($class);
            self::assertIsArray($helper->prepareArguments(), $class);
        }
    }

    private function formContext(): RenderingContext
    {
        $context = $this->context();
        $parameters = new \TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters();
        $request = new \TYPO3\CMS\Extbase\Mvc\Request((new \TYPO3\CMS\Core\Http\ServerRequest())->withAttribute('extbase', $parameters));
        $context->setAttribute(\Psr\Http\Message\ServerRequestInterface::class, $request);
        $context->getViewHelperVariableContainer()->addOrUpdate(\TYPO3\CMS\Fluid\ViewHelpers\FormViewHelper::class, 'fieldNamePrefix', 'tx_typo3forum_forum');
        return $context;
    }

    public function testForumSelectUsesCoreSelectionAndFormTokenRegistration(): void
    {
        $context = $this->formContext();
        $child = $this->createStub(\Mittwald\Typo3Forum\Domain\Model\Forum\Forum::class);
        $child->method('getUid')->willReturn(7);
        $child->method('getTitle')->willReturn('<Child>');
        $child->method('getChildren')->willReturn(new \TYPO3\CMS\Extbase\Persistence\ObjectStorage());
        $children = new \TYPO3\CMS\Extbase\Persistence\ObjectStorage();
        $children->attach($child);
        $root = $this->createStub(\Mittwald\Typo3Forum\Domain\Model\Forum\Forum::class);
        $root->method('getTitle')->willReturn('Root');
        $root->method('getChildren')->willReturn($children);
        $roots = new \TYPO3\CMS\Extbase\Persistence\ObjectStorage();
        $roots->attach($root);
        $repository = $this->createStub(\Mittwald\Typo3Forum\Domain\Repository\Forum\ForumRepository::class);
        $repository->method('findRootForums')->willReturn($roots);
        $html = $context->getViewHelperInvoker()->invoke(new ForumSelectViewHelper($repository), ['name' => 'moveTopicTarget', 'value' => '7'], $context);
        self::assertStringContainsString('<select name="tx_typo3forum_forum[moveTopicTarget]">', $html);
        self::assertStringContainsString('selected="selected" value="7">&lt;Child&gt;</option>', $html);
        self::assertContains('tx_typo3forum_forum[moveTopicTarget]', $context->getViewHelperVariableContainer()->get(\TYPO3\CMS\Fluid\ViewHelpers\FormViewHelper::class, 'formFieldNames'));
    }

    public function testBbCodeEditorRendersTextareaAndExistingPreviewContract(): void
    {
        $context = $this->formContext();
        $request = $context->getAttribute(\Psr\Http\Message\ServerRequestInterface::class);
        $page = new \TYPO3\CMS\Frontend\Page\PageInformation();
        $page->setId(42);
        $request = $request->withAttribute('frontend.page.information', $page);
        $context->setAttribute(\Psr\Http\Message\ServerRequestInterface::class, $request);
        $reader = $this->createStub(\Mittwald\Typo3Forum\Utility\TypoScript::class);
        $reader->method('loadTyposcriptFromPath')->willReturn(['panels.' => []]);
        $uriBuilder = $this->createMock(\TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder::class);
        $uriBuilder->method('reset')->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setRequest')->with($request)->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setTargetPageUid')->with(42)->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setArguments')->with(['type' => 43568275])->willReturnSelf();
        $uriBuilder->expects(self::once())->method('uriFor')->with('preview', [], 'Ajax', 'Typo3Forum', 'Ajax')->willReturn('/preview');
        $helper = new \Mittwald\Typo3Forum\ViewHelpers\Form\BbCodeEditorViewHelper($this->createStub(\TYPO3\CMS\Core\Cache\Frontend\FrontendInterface::class), $reader, $uriBuilder);
        $html = $context->getViewHelperInvoker()->invoke($helper, ['name' => 'post[text]', 'id' => 'editor', 'value' => '<text>', 'rows' => '7'], $context);
        self::assertStringContainsString('tx_typo3forum_ajax[text]', $html);
        self::assertStringContainsString('document.getElementById("editor")', $html);
        self::assertStringContainsString('<textarea id="editor" rows="7" name="tx_typo3forum_forum[post][text]">&lt;text&gt;</textarea>', $html);
        self::assertContains('tx_typo3forum_forum[post][text]', $context->getViewHelperVariableContainer()->get(\TYPO3\CMS\Fluid\ViewHelpers\FormViewHelper::class, 'formFieldNames'));
    }

    public function testUploadValidatorAcceptsActualPsr7Files(): void
    {
        $attachment = $this->createStub(\Mittwald\Typo3Forum\Domain\Model\Forum\Attachment::class);
        $attachment->method('getAllowedMimeTypes')->willReturn(['text/plain']);
        $attachment->method('getAllowedMaxSize')->willReturn(20);
        $this->services[\Mittwald\Typo3Forum\Domain\Model\Forum\Attachment::class] = $attachment;
        $validator = new \Mittwald\Typo3Forum\Domain\Validator\Forum\AttachmentPlainValidator();
        $validator->setOptions([]);
        $file = static fn($size, $error, $type) => new \TYPO3\CMS\Core\Http\UploadedFile('unused-test-file', $size, $error, 'file.txt', $type);
        self::assertFalse($validator->validate([$file(10, UPLOAD_ERR_OK, 'text/plain')])->hasErrors());
        self::assertFalse($validator->validate([$file(0, UPLOAD_ERR_NO_FILE, '')])->hasErrors());
        self::assertTrue($validator->validate([$file(21, UPLOAD_ERR_OK, 'text/plain')])->hasErrors());
        self::assertTrue($validator->validate([$file(10, UPLOAD_ERR_OK, 'text/html')])->hasErrors());
        self::assertTrue($validator->validate([$file(0, UPLOAD_ERR_PARTIAL, 'text/plain')])->hasErrors());
    }

    public function testUploadServiceHandsPsr7FileToFal(): void
    {
        $file = new \TYPO3\CMS\Core\Http\UploadedFile('unused-test-file', 10, UPLOAD_ERR_OK, 'example.txt', 'text/plain');
        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $folder = $this->createStub(\TYPO3\CMS\Core\Resource\Folder::class);
        $storage->method('hasFolder')->willReturn(true);
        $storage->method('getFolder')->with('frontend_uploads')->willReturn($folder);
        $storage->expects(self::once())->method('addUploadedFile')->with(
            $file, $folder, self::matchesRegularExpression('/^[a-f0-9]{40}\.txt$/'), \TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::REPLACE
        )->willThrowException(new \RuntimeException('FAL boundary reached'));
        $factory = $this->createStub(\TYPO3\CMS\Core\Resource\StorageRepository::class);
        $factory->method('getDefaultStorage')->willReturn($storage);
        $service = new \Mittwald\Typo3Forum\Service\AttachmentService($factory);
        self::assertCount(0, $service->initAttachments([new \TYPO3\CMS\Core\Http\UploadedFile('unused-test-file', 0, UPLOAD_ERR_NO_FILE)]));
        $this->expectExceptionMessage('FAL boundary reached');
        $service->initAttachments([$file]);
    }

    public function testParserReceivesConfiguredServiceOptions(): void
    {
        $service = $this->createMock(\Mittwald\Typo3Forum\TextParser\Service\QuoteParserService::class);
        $service->expects(self::once())->method('setSettings')->with(['template' => '/custom/Quote.html'])->willReturnSelf();
        $this->services[$service::class] = $service;
        $reader = $this->createStub(\Mittwald\Typo3Forum\Utility\TypoScript::class);
        $reader->method('loadTyposcriptFromPath')->willReturn(['enabledServices.' => ['quotes' => $service::class, 'quotes.' => ['template' => '/custom/Quote.html']]]);
        (new \Mittwald\Typo3Forum\TextParser\TextParserService($reader))->loadConfiguration();
    }

    public function testPreviewPreservesZeroAndEmptyContent(): void
    {
        $context = $this->context();
        foreach (['0', ''] as $content) {
            $parser = $this->createMock(\Mittwald\Typo3Forum\TextParser\TextParserService::class);
            $parser->expects(self::once())->method('parseText')->with($content)->willReturn($content);
            $helper = new \Mittwald\Typo3Forum\ViewHelpers\Format\TextParserViewHelper($parser);
            self::assertSame($content, $context->getViewHelperInvoker()->invoke($helper, ['content' => $content], $context, static fn() => 'fallback'));
        }
    }

    public function testRowReadsValidationErrorsFromExtbaseRequest(): void
    {
        $language = $this->createStub(\TYPO3\CMS\Core\Localization\LanguageService::class);
        $language->method('translate')->willReturn('Invalid <value>');
        $factory = $this->createStub(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
        $factory->method('create')->willReturn($language);
        $locales = $this->createStub(\TYPO3\CMS\Core\Localization\Locales::class);
        $locales->method('createLocaleFromRequest')->willReturn(new \TYPO3\CMS\Core\Localization\Locale('en'));
        $this->services[\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class] = $factory;
        $this->services[\TYPO3\CMS\Core\Localization\Locales::class] = $locales;
        $context = $this->formContext();
        $request = $context->getAttribute(\Psr\Http\Message\ServerRequestInterface::class);
        $results = new \TYPO3\CMS\Extbase\Error\Result();
        $results->forProperty('post.text')->addError(new \TYPO3\CMS\Extbase\Error\Error('Invalid', 42));
        $request->getAttribute('extbase')->setOriginalRequestMappingResults($results);
        $html = $context->getViewHelperInvoker()->invoke(\Mittwald\Typo3Forum\ViewHelpers\Form\RowViewHelper::class, ['label' => '<Label>', 'labelFor' => 'editor', 'error' => 'post.text', 'errorLLPrefix' => 'Error'], $context, static fn() => '');
        self::assertStringContainsString('class="control-group error"', $html);
        self::assertStringContainsString('&lt;Label&gt;', $html);
        self::assertStringContainsString('Invalid &lt;value&gt;', $html);
    }

    public function testUserAndRootlineLinksDelegateToCoreWithTheirPluginTargets(): void
    {
        $context = $this->formContext();
        $context->getVariableProvider()->add('settings', ['pids' => ['UserShow' => 23, 'Forum' => 24], 'cutUsernameOnChar' => 3]);
        $uri = $this->createMock(\TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder::class);
        foreach (['reset', 'setRequest', 'setNoCache', 'setLanguage', 'setSection', 'setFormat', 'setLinkAccessRestrictedPages', 'setArguments', 'setCreateAbsoluteUri', 'setTargetPageUid'] as $method) {
            $uri->method($method)->willReturnSelf();
        }
        $routes = [];
        $uri->expects(self::exactly(2))->method('uriFor')->willReturnCallback(static function (...$args) use (&$routes) { $routes[] = $args; return '/target?one=1&two=2'; });
        $this->services[\TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder::class] = $uri;
        $user = $this->createStub(FrontendUser::class);
        $user->method('getUid')->willReturn(7);
        $user->method('getUsername')->willReturn('<Name>');
        $html = $context->getViewHelperInvoker()->invoke(\Mittwald\Typo3Forum\ViewHelpers\User\LinkViewHelper::class, ['user' => $user], $context);
        self::assertStringContainsString('title="&lt;Name&gt;">&lt;Na...', $html);
        self::assertStringContainsString('href="/target?one=1&amp;two=2"', $html);
        $forum = $this->createStub(\Mittwald\Typo3Forum\Domain\Model\Forum\Forum::class);
        $forum->method('getUid')->willReturn(9);
        $forum->method('getTitle')->willReturn('<Forum>');
        $html = $context->getViewHelperInvoker()->invoke(\Mittwald\Typo3Forum\ViewHelpers\Forum\RootlineViewHelper::class, ['rootline' => [$forum]], $context);
        self::assertStringContainsString('&lt;Forum&gt;</a>', $html);
        self::assertSame([['show', ['user' => 7], 'User', 'Typo3Forum', 'UserProfile'], ['show', ['forum' => 9], 'Forum', 'Typo3Forum', 'Forum']], $routes);
    }

    public function testQuoteViewFactoryRendersActualPartialWithSettingsAndAuthorLink(): void
    {
        $language = $this->createStub(\TYPO3\CMS\Core\Localization\LanguageService::class);
        $language->method('translate')->willReturn('Quote');
        $language->method('getLocale')->willReturn(new \TYPO3\CMS\Core\Localization\Locale('en'));
        $factory = $this->createStub(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
        $factory->method('create')->willReturn($language);
        $locales = $this->createStub(\TYPO3\CMS\Core\Localization\Locales::class);
        $locales->method('createLocaleFromRequest')->willReturn(new \TYPO3\CMS\Core\Localization\Locale('en'));
        $this->services[\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class] = $factory;
        $this->services[\TYPO3\CMS\Core\Localization\Locales::class] = $locales;
        $uri = $this->createMock(\TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder::class);
        foreach (['reset', 'setRequest', 'setNoCache', 'setLanguage', 'setSection', 'setFormat', 'setLinkAccessRestrictedPages', 'setArguments', 'setCreateAbsoluteUri'] as $method) {
            $uri->method($method)->willReturnSelf();
        }
        $uri->expects(self::once())->method('setTargetPageUid')->with(23)->willReturnSelf();
        $uri->expects(self::once())->method('uriFor')->with('show', ['user' => 7], 'User', 'Typo3Forum', 'UserProfile')->willReturn('/profile?user=7&test=1');
        $this->services[\TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder::class] = $uri;
        $typoScript = new \TYPO3\CMS\Core\TypoScript\FrontendTypoScript(new \TYPO3\CMS\Core\TypoScript\AST\Node\RootNode(), [], [], []);
        $typoScript->setSetupArray(['plugin.' => ['tx_typo3forum.' => ['settings.' => ['pids.' => ['UserShow' => 23]]]]]);
        $request = $this->formContext()->getAttribute(\Psr\Http\Message\ServerRequestInterface::class)->withAttribute('frontend.typoscript', $typoScript);
        $previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $GLOBALS['TYPO3_REQUEST'] = $request;
        try {
            $author = $this->createStub(FrontendUser::class);
            $author->method('getUid')->willReturn(7);
            $author->method('getUsername')->willReturn('<Author>');
            $post = $this->createStub(\Mittwald\Typo3Forum\Domain\Model\Forum\Post::class);
            $post->method('getAuthor')->willReturn($author);
            $repository = $this->createStub(\Mittwald\Typo3Forum\Domain\Repository\Forum\PostRepository::class);
            $repository->method('findByUid')->willReturn($post);
            $viewFactory = $this->createMock(\TYPO3\CMS\Core\View\ViewFactoryInterface::class);
            $viewFactory->expects(self::exactly(2))->method('create')->willReturnCallback(function ($data) use ($request) {
                self::assertSame($request, $data->request);
                self::assertSame('EXT:typo3_forum/Resources/Private/Partials/Bootstrap/Format/Quote.html', $data->templatePathAndFilename);
                $context = $this->context();
                $context->setAttribute(\Psr\Http\Message\ServerRequestInterface::class, $request);
                $context->getTemplatePaths()->setTemplatePathAndFilename(dirname(__DIR__, 2) . '/Resources/Private/Partials/Bootstrap/Format/Quote.html');
                return new \TYPO3\CMS\Fluid\View\FluidViewAdapter(new \TYPO3Fluid\Fluid\View\TemplateView($context));
            });
            $parser = new \Mittwald\Typo3Forum\TextParser\Service\QuoteParserService($repository, $viewFactory);
            $html = $parser->getParsedText('[quote=1]<strong>Already parsed</strong>[/quote]');
            self::assertStringContainsString('&lt;Author&gt;', $html);
            self::assertStringContainsString('/profile?user=7&amp;test=1', $html);
            self::assertStringContainsString('<strong>Already parsed</strong>', $html);
            self::assertStringContainsString('Anonymous quote', $parser->getParsedText('[quote]Anonymous quote[/quote]'));
        } finally {
            if ($previousRequest === null) { unset($GLOBALS['TYPO3_REQUEST']); } else { $GLOBALS['TYPO3_REQUEST'] = $previousRequest; }
        }
    }
}
