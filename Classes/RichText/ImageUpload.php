<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\RichText;

use Psr\Http\Message\UploadedFileInterface;

/** Validate raster uploads without changing original bytes or metadata. */
final class ImageUpload
{
    /** @return array{key: string, name: string}|null */
    public static function reference(?string $filename): ?array
    {
        if (preg_match('/^forum-image-([a-f0-9]{32})--(.+)$/sD', $filename ?? '', $match)) {
            return ['key' => $match[1], 'name' => mb_substr(basename(str_replace('\\', '/', $match[2])), 0, 255)];
        }
        // Accept a still-cached editor from the previous upload implementation.
        if (preg_match('/^forum-image-([a-f0-9]{32})\.(jpg|png)$/D', $filename ?? '', $match)) {
            return ['key' => $match[1], 'name' => 'image.' . $match[2]];
        }
        return null;
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, mime: string, bits?: int, channels?: int} */
    public function inspect(string $bytes): array
    {
        if (strlen($bytes) > 20971520) {
            throw new \RuntimeException('Image exceeds 20 MiB.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 16000000) {
            throw new \RuntimeException('Invalid image or image dimensions exceed the limit.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new \RuntimeException('Cannot decode image.');
        }
        imagedestroy($image);
        return $info;
    }

    public function validate(UploadedFileInterface $file): string
    {
        $stream = $file->getStream();
        $stream->rewind();
        $bytes = $stream->read(20971521);
        $stream->rewind();
        return $this->inspect($bytes)['mime'];
    }
}
