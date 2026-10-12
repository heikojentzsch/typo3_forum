<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\RichText;

/** Add request-specific source links at display time, never to stored quote content. */
final class QuoteRenderer
{
    /** @param callable(int): string $postUrl */
    public function render(string $html, callable $postUrl, string $label): string
    {
        $html = (new Html())->sanitize($html);
        return preg_replace_callback('/<blockquote data-post="([0-9]+)">/', static function (array $match) use ($postUrl, $label): string {
            $uid = (int)$match[1];
            if ($uid <= 0) {
                return $match[0];
            }
            $url = $postUrl($uid);
            if (!Html::url($url)) {
                return $match[0];
            }
            return $match[0] . '<p class="forum-quote-source"><a href="' . Html::escape($url)
                . '" target="_blank" rel="noopener noreferrer">' . Html::escape($label) . ' #' . $uid . '</a></p>';
        }, $html) ?? $html;
    }
}
