<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Service\Notification;

final class NotificationEmailRenderer
{
    /** @param array<string, string> $markers */
    public function render(
        string $template,
        array $markers,
        bool $includeUnsubscribeLink,
        bool $includePostText = true
    ): string {
        if (!$includeUnsubscribeLink) {
            $template = $this->removeLinesContaining($template, '###UNSUBSCRIBE_LINK###');
        }
        if (!$includePostText) {
            $template = $this->removeLinesContaining($template, '###POST_TEXT###');
        }
        return nl2br(trim(strtr($template, $markers)), false);
    }

    /** @param array<string, string> $markers */
    public function renderFragment(string $template, array $markers): string
    {
        return trim(strtr($template, $markers));
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public function link(string $url, string $label): string
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $escapedLabel = $this->escape($label);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $escapedLabel;
        }
        return '<a href="' . $this->escape($url) . '">' . $escapedLabel . '</a>';
    }

    public function sanitizeSubject(string $subject): string
    {
        $subject = html_entity_decode(strip_tags($subject), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $subject = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $subject) ?? '');
    }

    private function removeLinesContaining(string $template, string $marker): string
    {
        $lines = preg_split('/\R/', $template) ?: [];
        $lines = array_filter($lines, static fn (string $line): bool => !str_contains($line, $marker));
        return implode("\n", $lines);
    }
}
