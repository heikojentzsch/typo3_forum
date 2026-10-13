<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\RichText\Html;
use Mittwald\Typo3Forum\RichText\ImageUpload;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\UploadedFile;

final class ImageUploadTest extends TestCase
{
    public function testValidationLeavesOriginalBytesAndDimensionsUntouched(): void
    {
        $image = imagecreatetruecolor(2400, 1200);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean() . '<script>payload</script>';
        imagedestroy($image);
        $stream = new Stream('php://temp', 'w+b');
        $stream->write($bytes);
        $stream->rewind();
        $file = new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'untrusted.png', 'image/png');
        self::assertSame('image/png', (new ImageUpload())->validate($file));
        self::assertSame($bytes, (string)$file->getStream());
        self::assertSame(2400, getimagesizefromstring((string)$file->getStream())[0]);

    }

    public function testSvgAndMislabelledContentAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ImageUpload())->inspect('<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>');
    }

    public function testOversizedBytesAreRejectedBeforeDecoding(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ImageUpload())->inspect(str_repeat('x', 20971521));
    }

    public function testStoredImageContainsOnlyItsReferenceAndSafeAltText(): void
    {
        $key = str_repeat('a', 32);
        $source = '<p><img data-forum-image="' . $key . '" src="blob:local-preview" onerror="bad()" alt="&lt;Photo&gt;"></p>';
        $post = new Post();
        $post->setText($source);
        self::assertSame('<p><img data-forum-image="' . $key . '" alt="&lt;Photo&gt;"></p>', $post->getTextHtml());
        self::assertSame('[Image]', $post->getText());
        self::assertSame($post->getTextHtml(), (new Html())->sanitize($post->getTextHtml()));
        self::assertSame('', (new Html())->sanitize('<img data-forum-image="bad" src="blob:bad">'));
    }
}
