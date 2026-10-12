<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\RichText\Html;
use Mittwald\Typo3Forum\RichText\LegacyConverter;
use PHPUnit\Framework\TestCase;

final class RichTextTest extends TestCase
{
    public function testUnicodeAndAllowedFormattingSurviveSanitization(): void
    {
        $html = '<p><strong>Ä 👍🏽 👨‍👩‍👧‍👦</strong> <u>unterstrichen</u> <em>日本語</em></p>';
        self::assertSame($html, (new Html())->sanitize($html));
        self::assertSame($html, (new Html())->sanitize((new Html())->sanitize($html)));
    }

    public function testLinkTargetsAreEnforcedEvenForPastedOrManipulatedHtml(): void
    {
        $html = (new Html())->sanitize('<a href="https://example.org" target="_self" rel="opener">Link</a>');
        self::assertSame('<a href="https://example.org" target="_blank" rel="nofollow ugc noopener noreferrer">Link</a>', $html);
        self::assertSame($html, (new Html())->sanitize($html));
    }

    public function testNestedQuoteReferencesGetEscapedSourceLinksOnlyAtRenderTime(): void
    {
        $source = '<blockquote data-post="42"><p>Outer</p><blockquote data-post="7">Inner</blockquote></blockquote><blockquote>Plain</blockquote>';
        $rendered = (new \Mittwald\Typo3Forum\RichText\QuoteRenderer())->render($source, static fn (int $uid): string => '/post?uid=' . $uid . '&view=show', 'Original <post>');
        self::assertSame(2, substr_count($rendered, 'class="forum-quote-source"'));
        self::assertStringContainsString('href="/post?uid=42&amp;view=show"', $rendered);
        self::assertStringContainsString('Original &lt;post&gt; #7', $rendered);
        self::assertStringContainsString('<blockquote>Plain</blockquote>', $rendered);
        self::assertSame($source, (new Html())->sanitize($source));
        self::assertSame('<blockquote data-post="42">Text</blockquote>', (new \Mittwald\Typo3Forum\RichText\QuoteRenderer())->render('<blockquote data-post="42">Text</blockquote>', static fn (int $uid): string => 'javascript:bad', 'Original'));
    }

    public function testActiveContentAndUnsafeUrlsCannotBeStored(): void
    {
        $html = '<script>alert(1)</script><svg onload="evil()">bad</svg><p onclick="evil()">ok<a href="javascript:alert(1)">link</a><img src="data:text/html,bad" onerror="evil()"></p>';
        self::assertSame('<p>oklink</p>', (new Html())->sanitize($html));
        self::assertFalse(Html::url('java' . "\n" . 'script:alert(1)'));
        self::assertFalse(Html::url('//evil.example'));
        self::assertFalse(Html::url('/\\evil.example'));
    }

    public function testNestedQuotesCodeAndSmileysConvertWithoutTouchingCodeOrUrls(): void
    {
        $result = (new LegacyConverter())->convert('[quote=42][b]Hallo [i]Welt[/i][/b] 🙂 :)[/quote]' . "\n" . '[code language=php]<b> :) [b]literal[/b]</b>[/code] https://example.test/:/');
        self::assertSame([], $result['warnings']);
        self::assertStringContainsString('<blockquote data-post="42"><strong>Hallo <em>Welt</em></strong>', $result['html']);
        self::assertStringContainsString('<code class="language-php">&lt;b&gt; :) [b]literal[/b]&lt;/b&gt;</code>', $result['html']);
        self::assertStringContainsString('https://example.test/:/', $result['html']);
    }

    public function testListsAndUnknownTagsAreNotSilentlyLost(): void
    {
        $converter = new LegacyConverter();
        self::assertSame('<ul><li>one</li><li><u>two</u></li></ul>', $converter->convert('[list][*]one[*][u]two[/u][/list]')['html']);
        $unknown = $converter->convert('[spoiler]hidden[/spoiler]');
        self::assertNotEmpty($unknown['warnings']);
        self::assertStringContainsString('[spoiler]', $unknown['html']);
        self::assertNotEmpty($converter->convert('[b]unclosed')['warnings']);
    }

    public function testLegacyLinksPreserveLabelsAndNormalizeWwwAndAdjacentSmileys(): void
    {
        $result = (new LegacyConverter())->convert('[url]www.example.org[/url] [url=https://example.org][b]Label[/b][/url] Hi:)!');
        self::assertSame([], $result['warnings']);
        self::assertStringContainsString('href="https://www.example.org"', $result['html']);
        self::assertStringContainsString('<strong>Label</strong></a>', $result['html']);
        self::assertStringContainsString('Hi🙂!', $result['html']);
        self::assertSame('8) numbered item', (new LegacyConverter())->convert('8) numbered item')['html']);
    }

    public function testPostsStoreSanitizedHtmlAndPlainTextForSearchAndNotifications(): void
    {
        $post = new Post();
        $post->setText('<p><strong>Hello 🙂</strong></p><script>bad</script>');
        self::assertSame('<p><strong>Hello 🙂</strong></p>', $post->getTextHtml());
        self::assertSame('Hello 🙂', $post->getText());
        self::assertSame($post->getTextHtml(), $post->getEditorText());
        $post->setText('<p>&nbsp;</p>');
        self::assertSame('', trim(str_replace("\u{00A0}", ' ', $post->getText())));
    }
}
