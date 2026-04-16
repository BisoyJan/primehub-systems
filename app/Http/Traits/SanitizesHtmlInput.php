<?php

namespace App\Http\Traits;

use Closure;

/**
 * Sanitizes rich text HTML fields to allow only safe formatting tags.
 * Apply in FormRequest classes by calling $this->sanitizeHtmlFields() in prepareForValidation().
 */
trait SanitizesHtmlInput
{
    /**
     * The allowed HTML tags for rich text fields.
     */
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><s><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><a><span><sub><sup>';

    /**
     * The rich text field names to sanitize.
     *
     * @return array<string>
     */
    protected function htmlFields(): array
    {
        return [
            'performance_description',
            'agent_strengths_wins',
            'smart_action_plan',
        ];
    }

    /**
     * Strip dangerous HTML tags from rich text fields, keeping safe formatting.
     */
    protected function sanitizeHtmlFields(): void
    {
        foreach ($this->htmlFields() as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $value = $this->input($field);

                // Remove event handler attributes (onerror, onclick, etc.)
                $value = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value);

                // Remove javascript: protocol from href/src attributes
                $value = preg_replace('/(?:href|src)\s*=\s*(?:"javascript:[^"]*"|\'javascript:[^\']*\')/i', '', $value);

                // Strip all tags except allowed ones
                $value = strip_tags($value, self::ALLOWED_TAGS);

                // Strip style attributes from all tags except list-style-type on <ol>
                // This prevents pasted content from Word/Google Docs from carrying over
                // unwanted font-family, font-size, line-height, margin, etc.
                $value = preg_replace_callback(
                    '/<(ol|li|span|p|strong|b|em|i|u|s|h[1-6]|blockquote|a|sub|sup)\b([^>]*?)>/i',
                    function ($matches) {
                        $tag = strtolower($matches[1]);
                        $attrs = $matches[2];

                        if ($tag === 'ol') {
                            // Preserve only list-style-type on <ol>
                            if (preg_match('/list-style-type\s*:\s*([^;"]+)/i', $attrs, $styleMatch)) {
                                return '<' . $matches[1] . ' style="list-style-type: ' . trim($styleMatch[1]) . '">';
                            }
                        }

                        if ($tag === 'a') {
                            // Preserve href on anchors
                            if (preg_match('/href\s*=\s*"([^"]*)"/i', $attrs, $hrefMatch)) {
                                return '<a href="' . $hrefMatch[1] . '">';
                            }
                        }

                        // Strip all attributes (including style) from other tags
                        return '<' . $matches[1] . '>';
                    },
                    $value
                );

                $this->merge([$field => $value]);
            }
        }
    }

    /**
     * Create a validation rule that checks minimum length after stripping HTML tags.
     * Use this for rich text fields where HTML like <p><br></p> should not count.
     */
    protected function richTextMinLength(int $min): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($min) {
            if (! is_string($value)) {
                return;
            }

            $plainText = trim(strip_tags(html_entity_decode($value)));

            if (mb_strlen($plainText) < $min) {
                $fail("The {$attribute} must be at least {$min} characters of actual text.");
            }
        };
    }
}
