<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\RichText;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/** Intentionally small storage vocabulary for forum rich text. */
final class Html
{
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function url(string $url, bool $image = false): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        return preg_match($image ? '~^https?://[^/]+~i' : '~^(?:https?://[^/]+|mailto:[^@]+@|/(?!/)|\#)~i', $url) === 1;
    }

    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $body = $document->getElementsByTagName('body')->item(0);
            return $body === null ? '' : $this->children($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function plainText(string $html): string
    {
        $html = $this->sanitize($html);
        $html = preg_replace('~</(?:p|div|li|blockquote|pre)>|<br\s*/?>~i', "\n", $html) ?? '';
        $html = preg_replace('/<img\b[^>]*>/', '[Image]', $html) ?? $html;
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function children(DOMNode $parent): string
    {
        $html = '';
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMText) {
                $html .= self::escape($node->data);
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form', 'input', 'button'], true)) {
                continue;
            }
            $tag = ['b' => 'strong', 'i' => 'em', 'div' => 'p'][$tag] ?? $tag;
            $inner = $this->children($node);
            if (!in_array($tag, ['p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'img'], true)) {
                $html .= $tag === 'span' && preg_match('/(?:^|;)\s*text-decoration(?:-line)?\s*:\s*underline\s*(?:;|$)/i', $node->getAttribute('style')) ? '<u>' . $inner . '</u>' : $inner;
                continue;
            }
            $attributes = '';
            if ($tag === 'a') {
                $href = $node->getAttribute('href');
                if (!self::url($href)) {
                    $html .= $inner;
                    continue;
                }
                $attributes = ' href="' . self::escape($href) . '" target="_blank" rel="nofollow ugc noopener noreferrer"';
            } elseif ($tag === 'img') {
                $imageKey = $node->getAttribute('data-forum-image');
                if (preg_match('/^[a-f0-9]{32}$/D', $imageKey)) {
                    $html .= '<img data-forum-image="' . $imageKey . '" alt="' . self::escape($node->getAttribute('alt')) . '">';
                    continue;
                }
                $src = $node->getAttribute('src');
                if (!self::url($src, true)) {
                    $html .= self::escape($node->getAttribute('alt'));
                    continue;
                }
                $attributes = ' src="' . self::escape($src) . '" alt="' . self::escape($node->getAttribute('alt')) . '"';
            } elseif ($tag === 'blockquote' && ctype_digit($node->getAttribute('data-post'))) {
                $attributes = ' data-post="' . (int)$node->getAttribute('data-post') . '"';
            } elseif ($tag === 'code' && preg_match('/^language-[a-z0-9+-]+$/i', $node->getAttribute('class'))) {
                $attributes = ' class="' . self::escape($node->getAttribute('class')) . '"';
            }
            $html .= '<' . $tag . $attributes . '>' . (in_array($tag, ['br', 'img'], true) ? '' : $inner . '</' . $tag . '>');
        }
        return $html;
    }
}
