<?php

namespace Mittwald\Typo3Forum\Service;

use Mittwald\Typo3Forum\Domain\Model\Forum\Attachment;
use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\RichText\ImageUpload;
use Mittwald\Typo3Forum\RichText\PrivateImageStorage;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\FileReference as CoreFileReference;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReference;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class AttachmentService implements SingletonInterface
{
    protected ?ResourceStorage $storage;
    /** @var array<string, string> */
    private array $inlineImageKeys = [];

    public function __construct(
        StorageRepository $storageRepository
    ) {
        $this->storage = $storageRepository->getDefaultStorage();
    }

    /**
     * Converts uploaded files to attachment objects.
     * @param list<UploadedFileInterface> $uploadedAttachments
     * @return ObjectStorage
     * @phpstan-return \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\Mittwald\Typo3Forum\Domain\Model\Forum\Attachment>
     */
    public function initAttachments(array $uploadedAttachments): ObjectStorage
    {
        /** @var ObjectStorage<Attachment> $attachmentStorage */
        $attachmentStorage = new ObjectStorage();

        foreach ($uploadedAttachments as $attachmentData) {
            if ($attachmentData->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $reference = ImageUpload::reference($attachmentData->getClientFilename());
            $storage = $reference !== null ? GeneralUtility::makeInstance(PrivateImageStorage::class)->get() : $this->storage;
            if ($storage === null) {
                throw new \RuntimeException('Cannot initialize forum attachments: no default FAL storage is configured.', 1788880001);
            }
            $filename = $attachmentData->getClientFilename() ?? '';
            $imageKey = null;
            $imageExtension = null;
            if ($reference !== null) {
                $mime = (new ImageUpload())->validate($attachmentData);
                $imageExtension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
                $imageKey = bin2hex(random_bytes(16));
                $this->inlineImageKeys[$reference['key']] = $imageKey;
                $filename = $reference['name'];
            }

            // Build extbase file reference object to the uploaded file.
            // TODO: Figure out where to grab the folder name from
            $folderIdentifier = 'frontend_uploads';
            if (!$storage->hasFolder($folderIdentifier)) {
                $storage->createFolder($folderIdentifier);
            }

            // Retain the old extensionless-name behavior as well as dotted filenames.
            $nameParts = explode('.', $filename);
            $extension = $imageExtension ?? end($nameParts);
            $falFile = $storage->addUploadedFile(
                $attachmentData,
                $storage->getFolder($folderIdentifier),
                bin2hex(random_bytes(20)) . '.' . $extension,
                DuplicationBehavior::CANCEL
            );

            $falFileReference = GeneralUtility::makeInstance(
                CoreFileReference::class,
                [
                    'uid_local' => (int)$falFile->getProperty('uid'),
                ]
            );

            $extbaseFileReference = GeneralUtility::makeInstance(ExtbaseFileReference::class);
            $extbaseFileReference->setOriginalResource(
                $falFileReference
            );

            // Hydrate Attachment
            /** @var Attachment $attachment */
            $attachment = GeneralUtility::makeInstance(Attachment::class);

            $attachment
                ->setFileReference($extbaseFileReference)
                ->setName($filename)
            ;

            if ($imageKey !== null) {
                $attachment->setInlineImageKey($imageKey);
            }
            $attachmentStorage->attach($attachment);
        }

        return $attachmentStorage;
    }

    /** Replace browser preview IDs with server-generated references before persistence. */
    public function bindInlineImages(Post $post): void
    {
        if ($this->inlineImageKeys === []) {
            return;
        }
        $html = preg_replace_callback(
            '/data-forum-image="([a-f0-9]{32})"/',
            fn (array $match): string => 'data-forum-image="' . ($this->inlineImageKeys[$match[1]] ?? $match[1]) . '"',
            $post->getEditorText()
        );
        $post->setText($html ?? $post->getEditorText());
        $this->inlineImageKeys = [];
    }
}
