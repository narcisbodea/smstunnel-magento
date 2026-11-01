<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model;

/**
 * Fills a text template's {{placeholders}}.
 */
class TemplateRenderer
{
    /**
     * A line holding one of these is left out when its value is empty (a shipment without tracking, say).
     */
    private const OPTIONAL = ['tracking_number', 'carrier'];

    /**
     * Fill the template. Unknown placeholders stay as they are, so a typo shows up in the test text.
     *
     * An empty value takes the space before it along ("from {{first}} {{last}}:" gives "from Ana:").
     *
     * @param string $template
     * @param array<string,string|int|float|null> $values placeholder name => value
     * @return string
     */
    public function render(string $template, array $values): string
    {
        $empty = [];
        foreach ($values as $name => $value) {
            if (trim((string) $value) === '') {
                $empty[] = preg_quote(strtolower((string) $name), '/');
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', $template) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            foreach (self::OPTIONAL as $name) {
                if (preg_match('/\{\{\s*' . $name . '\s*\}\}/i', $line) === 1
                    && trim((string) ($values[$name] ?? '')) === ''
                ) {
                    continue 2;
                }
            }
            $kept[] = $line;
        }
        $text = implode("\n", $kept);

        if ($empty !== []) {
            $text = (string) preg_replace('/[ \t]*\{\{\s*(?:' . implode('|', $empty) . ')\s*\}\}/i', '', $text);
        }
        $text = (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function (array $match) use ($values): string {
                $name = strtolower($match[1]);

                return array_key_exists($name, $values) ? trim((string) $values[$name]) : $match[0];
            },
            $text
        );
        // Tidy up what was left behind.
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
