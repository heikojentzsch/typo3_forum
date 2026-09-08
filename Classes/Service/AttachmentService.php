<?php

namespace Mittwald\Typo3Forum\Service;

use Mittwald\Typo3Forum\Domain\Model\Forum\Attachment;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\FileReference as CoreFileReference;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReference;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class AttachmentService implements SingletonInterface
{
    protected ?ResourceStorage $storage;

    public function __construct(
        StorageRepository $storageRepository
    ) {
        $this->storage = $storageRepository->getDefaultStorage();
    }

    /**
     * Converts uploaded files to attachment objects.
     * @param list<UploadedFileInterface> $uploadedAttachments
     * @return ObjectStorage
     */
    public function initAttachments(array $uploadedAttachments): ObjectStorage
    {
        /* @var \Mittwald\Typo3Forum\Domain\Model\Forum\Attachment */
        $attachmentStorage = new ObjectStorage();

        foreach ($uploadedAttachments as $attachmentData) {
            if ($attachmentData->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($this->storage === null) {
                throw new \RuntimeException('Cannot initialize forum attachments: no default FAL storage is configured.', 1788880001);
            }

            $filename = $attachmentData->getClientFilename() ?? '';

            // Build extbase file reference object to the uploaded file.
            // TODO: Figure out where to grab the folder name from
            $folderIdentifier = 'frontend_uploads';
            if (!$this->storage->hasFolder($folderIdentifier)) {
                $this->storage->createFolder($folderIdentifier);
            }

            // Retain the old extensionless-name behavior as well as dotted filenames.
            $nameParts = explode('.', $filename);
            $extension = end($nameParts);
            $falFile = $this->storage->addUploadedFile(
                $attachmentData,
                $this->storage->getFolder($folderIdentifier),
                sha1($filename . time()) . '.' . $extension,
                DuplicationBehavior::REPLACE
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

            $attachmentStorage->attach($attachment);
        }

        return $attachmentStorage;
    }
}
