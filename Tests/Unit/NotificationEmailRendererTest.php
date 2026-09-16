<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Service\Notification\NotificationEmailRenderer;
use PHPUnit\Framework\TestCase;

final class NotificationEmailRendererTest extends TestCase
{
    public function testMarkerLikeUserContentIsNotRecursivelyExpanded(): void
    {
        $renderer = new NotificationEmailRenderer();
        $result = $renderer->render(
            'Recipient: ###RECIPIENT### / Text: ###POST_TEXT### / Unsubscribe: ###UNSUBSCRIBE_LINK###',
            [
                '###RECIPIENT###' => 'Alice',
                '###POST_TEXT###' => $renderer->escape('literal ###RECIPIENT### and ###UNSUBSCRIBE_LINK###'),
                '###UNSUBSCRIBE_LINK###' => '<a href="https://example.test/unsubscribe">unsubscribe</a>',
            ],
            true
        );

        self::assertStringContainsString('Text: literal ###RECIPIENT### and ###UNSUBSCRIBE_LINK###', $result);
        self::assertSame(1, substr_count($result, 'Alice'));
        self::assertSame(1, substr_count($result, 'https://example.test/unsubscribe'));
    }

    public function testUnsafeMarkupAndUrlsRemainPlainEscapedText(): void
    {
        $renderer = new NotificationEmailRenderer();

        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $renderer->escape('<script>alert("x")</script>'));
        self::assertSame('click', $renderer->link('javascript:alert(1)', 'click'));
        self::assertSame('<a href="https://example.test/?a=1&amp;b=2">A &amp; B</a>', $renderer->link('https://example.test/?a=1&b=2', 'A & B'));
    }

    public function testDisabledLegacyUnsubscribeMarkerRemovesItsWholeLine(): void
    {
        $renderer = new NotificationEmailRenderer();
        $result = $renderer->render(
            "Hello\nUnsubscribe here: ###UNSUBSCRIBE_LINK###\nRegards",
            ['###UNSUBSCRIBE_LINK###' => ''],
            false
        );

        self::assertSame("Hello<br>\nRegards", $result);
        self::assertStringNotContainsString('Unsubscribe here', $result);
    }

    public function testDisabledLegacyPostTextMarkerRemovesItsWholeLine(): void
    {
        $renderer = new NotificationEmailRenderer();
        $result = $renderer->render(
            "Event\nMessage: ###POST_TEXT###\nRegards",
            ['###POST_TEXT###' => 'secret'],
            true,
            false
        );

        self::assertSame("Event<br>\nRegards", $result);
        self::assertStringNotContainsString('Message:', $result);
        self::assertStringNotContainsString('secret', $result);
    }

    public function testSubjectSanitizationPreventsHeaderInjectionWithoutHtmlEncoding(): void
    {
        $renderer = new NotificationEmailRenderer();
        self::assertSame('Forum & friends Bcc: victim@example.test', $renderer->sanitizeSubject("<b>Forum & friends</b>\r\nBcc: victim@example.test"));
    }
}
