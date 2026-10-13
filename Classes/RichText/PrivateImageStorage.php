<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\RichText;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class PrivateImageStorage
{
    public function __construct(private readonly StorageRepository $storages, private readonly ExtensionConfiguration $configuration)
    {
    }

    public function get(): ResourceStorage
    {
        $settings = $this->configuration->get('typo3_forum');
        $uid = (int)($settings['inlineImages']['storageUid'] ?? 0);
        $storage = $uid > 0 ? $this->storages->findByUid($uid) : $this->storages->getDefaultStorage();
        if ($storage === null || $storage->isPublic() || !$storage->isOnline() || !$storage->isWritable()) {
            throw new \RuntimeException('Configure an online, writable, non-public FAL storage for inline images in the extension settings.');
        }
        self::assertPrivate($storage);
        return $storage;
    }

    public static function assertPrivate(ResourceStorage $storage): void
    {
        if ($storage->isPublic()) {
            throw new \RuntimeException('Image originals and previews require private storage.');
        }
        if ($storage->getDriverType() === 'Local') {
            $config = $storage->getConfiguration();
            $base = ($config['pathType'] ?? '') === 'relative'
                ? Environment::getPublicPath() . '/' . $config['basePath']
                : (string)($config['basePath'] ?? '');
            $resolved = realpath($base);
            $public = realpath(Environment::getPublicPath());
            if ($resolved === false || $public === false || $resolved === $public || str_starts_with($resolved, $public . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('Local inline image storage must be outside the public web root.');
            }
        }
    }
}
