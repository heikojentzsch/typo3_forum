<?php

namespace Mittwald\Typo3Forum\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class TypoScriptRenderingService
{
    public function render(
        ServerRequestInterface $request,
        string $typoScriptObjectPath,
        mixed $data = [],
        ?string $currentValueKey = null,
        string $table = ''
    ): string {
        $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $contentObjectRenderer->setRequest($request);

        $parent = $request->getAttribute('currentContentObject');

        if ($parent instanceof ContentObjectRenderer) {
            $contentObjectRenderer->setParent(
                $parent->data,
                $parent->currentRecord
            );
        }

        $currentValue = null;

        if (is_object($data)) {
            $data = $data instanceof RecordInterface
                ? ($data->getRawRecord()?->toArray(true) ?? $data->toArray())
                : ObjectAccess::getGettableProperties($data);
        } elseif (is_string($data) || is_numeric($data)) {
            $currentValue = (string)$data;
            $data = [$data];
        }

        if (!is_array($data)) {
            $data = [];
        }

        $contentObjectRenderer->start($data, $table);

        if ($currentValue !== null) {
            $contentObjectRenderer->setCurrentVal($currentValue);
        } elseif (
            $currentValueKey !== null
            && isset($data[$currentValueKey])
        ) {
            $contentObjectRenderer->setCurrentVal(
                $data[$currentValueKey]
            );
        }

        $pathSegments = GeneralUtility::trimExplode(
            '.',
            $typoScriptObjectPath
        );

        $lastSegment = (string)array_pop($pathSegments);

        $configurationManager = GeneralUtility::getContainer()->get(
            ConfigurationManagerInterface::class
        );

        $setup = $configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );

        foreach ($pathSegments as $segment) {
            if (!array_key_exists($segment . '.', $setup)) {
                throw new \RuntimeException(
                    sprintf(
                        'TypoScript object path "%s" does not exist.',
                        $typoScriptObjectPath
                    ),
                    1788750001
                );
            }

            $setup = $setup[$segment . '.'];
        }

        if (!isset($setup[$lastSegment])) {
            throw new \RuntimeException(
                sprintf(
                    'No Content Object definition found at TypoScript object path "%s".',
                    $typoScriptObjectPath
                ),
                1788750002
            );
        }

        $timeTracker = GeneralUtility::makeInstance(TimeTracker::class);

        if ($timeTracker->LR) {
            $timeTracker->push(
                '/typo3-forum/typoscript-rendering/',
                '<' . $typoScriptObjectPath
            );
        }

        $timeTracker->incStackPointer();

        try {
            return $contentObjectRenderer->cObjGetSingle(
                $setup[$lastSegment],
                $setup[$lastSegment . '.'] ?? [],
                $typoScriptObjectPath
            );
        } finally {
            $timeTracker->decStackPointer();

            if ($timeTracker->LR) {
                $timeTracker->pull();
            }
        }
    }
}