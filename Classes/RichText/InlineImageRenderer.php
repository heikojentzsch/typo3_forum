<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\RichText;

use TYPO3\CMS\Fluid\ViewHelpers\Uri\ActionViewHelper;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

final class InlineImageRenderer
{
    /** Receives already-sanitized HTML; URLs are always generated from the current site. */
    public function render(string $html, RenderingContextInterface $context, bool $gallery = false): string
    {
        $settings = $context->getVariableProvider()->get('settings');
        return preg_replace_callback('/<img data-forum-image="([a-f0-9]{32})" alt="([^"]*)">/', static function (array $match) use ($context, $settings, $gallery): string {
            $url = (string)$context->getViewHelperInvoker()->invoke(ActionViewHelper::class, [
                'pageUid' => (int)($settings['pids']['Forum'] ?? 0), 'pageType' => 43568276,
                'extensionName' => 'Typo3Forum', 'pluginName' => 'Forum', 'controller' => 'Post',
                'action' => 'inlineImage', 'arguments' => ['image' => $match[1], 'thumbnail' => true],
            ], $context, static fn (): string => '');
            $image = '<img data-forum-image="' . $match[1] . '" src="' . Html::escape($url) . '" alt="' . $match[2] . '" loading="lazy">';
            if (!$gallery) {
                return $image;
            }
            $originalUrl = (string)$context->getViewHelperInvoker()->invoke(ActionViewHelper::class, [
                'pageUid' => (int)($settings['pids']['Forum'] ?? 0), 'pageType' => 43568276,
                'extensionName' => 'Typo3Forum', 'pluginName' => 'Forum', 'controller' => 'Post',
                'action' => 'inlineImage', 'arguments' => ['image' => $match[1]],
            ], $context, static fn (): string => '');
            return '<a class="forum-gallery-item" data-forum-gallery href="' . Html::escape($originalUrl) . '" target="_blank" rel="noopener">' . $image . '</a>';
        }, $html) ?? $html;
    }
}
