<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\RichText;

/** Never evaluates database expressions or resolves smiley image resources. */
final class LegacyConverter
{
    public const SMILEYS = [':)' => '🙂', ';)' => '😉', ':|' => '😐', '>:(' => '😠', ':(' => '🙁', ':/' => '😕'];

    /** @return array{html:string, warnings:list<string>} */
    public function convert(string $text): array
    {
        $warnings = [];
        $tokens = preg_split('~(\[/?[a-z*][a-z0-9]*(?:(?:=| language=)[^\]\r\n]*)?\])~i', str_replace(["\r\n", "\r"], "\n", $text), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $index = 0;
        $html = $this->parse($tokens, $index, null, $warnings, 0);
        return ['html' => (new Html())->sanitize($html), 'warnings' => array_values(array_unique($warnings))];
    }

    /** @param list<string> $tokens
     *  @param list<string> $warnings
     */
    private function parse(array $tokens, int &$index, ?string $closing, array &$warnings, int $depth): string
    {
        $html = '';
        $listItem = false;
        while ($index < count($tokens)) {
            $token = $tokens[$index++];
            if ($closing !== null && strtolower($token) === '[/' . $closing . ']') {
                return $html . ($listItem ? '</li>' : '');
            }
            if (!preg_match('~^\[([a-z*][a-z0-9]*)(?:(?:=| language=)([^\]]*))?\]$~i', $token, $match)) {
                if (str_starts_with($token, '[/')) {
                    $warnings[] = 'Unmatched closing tag: ' . $token;
                }
                $html .= $this->text($token);
                continue;
            }
            $tag = strtolower($match[1]);
            $argument = trim($match[2] ?? '', '"\'');
            if ($tag === '*' && $closing === 'list') {
                $html .= ($listItem ? '</li>' : '') . '<li>';
                $listItem = true;
                continue;
            }
            if (!in_array($tag, ['b', 'i', 'u', 's', 'quote', 'list', 'url', 'img', 'code'], true) || $depth >= 50) {
                $warnings[] = 'Unsupported tag: ' . $token;
                $html .= Html::escape($token);
                continue;
            }
            if (in_array($tag, ['code', 'img'], true)) {
                $raw = '';
                while ($index < count($tokens) && strtolower($tokens[$index]) !== '[/' . $tag . ']') {
                    $raw .= $tokens[$index++];
                }
                if ($index === count($tokens)) {
                    $warnings[] = 'Unclosed tag: ' . $token;
                } else {
                    ++$index;
                }
                if ($tag === 'code') {
                    $class = preg_match('/^[a-z0-9+-]+$/i', $argument) ? ' class="language-' . Html::escape($argument) . '"' : '';
                    $html .= '<pre><code' . $class . '>' . Html::escape($raw) . '</code></pre>';
                } elseif ($tag === 'img') {
                    if (!Html::url(trim($raw), true)) {
                        $warnings[] = 'Unsupported image URL';
                    }
                    $html .= '<img src="' . Html::escape(trim($raw)) . '" alt="">';
                }
                continue;
            }
            $inner = $this->parse($tokens, $index, $tag, $warnings, $depth + 1);
            if ($tag === 'url') {
                $url = $argument !== '' ? $argument : (new Html())->plainText($inner);
                if (preg_match('~^www\.[a-z0-9.-]+\.[a-z]{2,}(?:[/?#]|$)~i', $url)) {
                    $url = 'https://' . $url;
                }
                if (!Html::url($url)) {
                    $warnings[] = 'Unsupported link URL';
                }
                $html .= '<a href="' . Html::escape($url) . '">' . $inner . '</a>';
                continue;
            }
            if ($tag === 'list' && !in_array($argument, ['', '1'], true)) {
                $warnings[] = 'Unsupported list style: ' . $argument;
            }
            if ($tag === 'quote' && $argument !== '' && !ctype_digit($argument)) {
                $inner = '<p>' . Html::escape($argument) . ':</p>' . $inner;
            }
            $element = ['b' => 'strong', 'i' => 'em', 'u' => 'u', 's' => 's', 'quote' => 'blockquote', 'list' => $argument === '' ? 'ul' : 'ol'][$tag];
            $attributes = $tag === 'quote' && ctype_digit($argument) ? ' data-post="' . (int)$argument . '"' : '';
            $html .= '<' . $element . $attributes . '>' . $inner . '</' . $element . '>';
        }
        if ($closing !== null) {
            $warnings[] = 'Unclosed tag: [' . $closing . ']';
        }
        return $html . ($listItem ? '</li>' : '');
    }

    private function text(string $text): string
    {
        $text = preg_replace_callback('~(?:https?://|www\.)[^\s<>]+|(?:' . implode('|', array_map(static fn (string $key): string => preg_quote($key, '~'), array_keys(self::SMILEYS))) . ')~u', static fn (array $m): string => self::SMILEYS[$m[0]] ?? $m[0], $text) ?? $text;
        return nl2br(Html::escape($text), false);
    }
}
