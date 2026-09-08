<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Service\AttachmentService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class AttachmentStorageTest extends TestCase
{
    private function serviceWithoutStorage(): AttachmentService
    {
        $repository = $this->createStub(StorageRepository::class);
        $repository->method('getDefaultStorage')->willReturn(null);
        return new AttachmentService($repository);
    }

    public function testActualUploadWithoutDefaultStorageThrowsClearException(): void
    {
        $service = $this->serviceWithoutStorage();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1788880001);
        $this->expectExceptionMessage('no default FAL storage is configured');
        $service->initAttachments([new UploadedFile('php://temp', 1, UPLOAD_ERR_OK, 'file.txt', 'text/plain')]);
    }

    public function testNoUploadStillNeedsNoStorage(): void
    {
        $service = $this->serviceWithoutStorage();
        self::assertCount(0, $service->initAttachments([]));
        self::assertCount(0, $service->initAttachments([new UploadedFile('php://temp', 0, UPLOAD_ERR_NO_FILE)]));
    }
}
