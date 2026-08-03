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

                // Strip unwanted style attributes from tags, preserving only safe styles.
                // Keeps pasted formatting from Word/Google Docs (bold, italic, color,
                // highlight, alignment, font-size) while dropping font-family, line-height,
                // margins, and other layout-breaking styles.
                $value = preg_replace_callback(
                    '/<(ol|li|span|p|strong|b|em|i|u|s|h[1-6]|blockquote|a|sub|sup)\b([^>]*?)>/i',
                    function ($matches) {
                        $tag = strtolower($matches[1]);
                        $attrs = $matches[2];

                        // Collect safe styles to preserve
                        $safeStyles = $this->extractSafeStyles($attrs);

                        // Preserve list-style-type on <ol> (used for nested list styles)
                        if ($tag === 'ol' && preg_match('/list-style-type\s*:\s*([^;"]+)/i', $attrs, $m)) {
                            $safeStyles[] = 'list-style-type: '.trim($m[1]);
                        }

                        if ($tag === 'a') {
                            // Preserve href on anchors
                            if (preg_match('/href\s*=\s*"([^"]*)"/i', $attrs, $hrefMatch)) {
                                $styleAttr = $safeStyles ? ' style="'.implode('; ', $safeStyles).'"' : '';

                                return '<a href="'.$hrefMatch[1].'"'.$styleAttr.'>';
                            }
                        }

                        if ($safeStyles) {
                            return '<'.$matches[1].' style="'.implode('; ', $safeStyles).'">';
                        }

                        // Strip all attributes from other tags
                        return '<'.$matches[1].'>';
                    },
                    $value
                );

                $this->merge([$field => $value]);
            }
        }
    }

    /**
     * Extract whitelisted, validated inline styles from a tag's attribute string.
     *
     * @return array<string>
     */
    private function extractSafeStyles(string $attrs): array
    {
        $properties = [
            'font-weight',
            'font-style',
            'text-decoration',
            'text-decoration-line',
            'color',
            'background-color',
            'text-align',
            'font-size',
        ];

        $safeStyles = [];

        foreach ($properties as $property) {
            // Use a negative lookbehind so "color" does not match "background-color".
            if (preg_match('/(?<![a-z-])'.preg_quote($property, '/').'\s*:\s*([^;"]+)/i', $attrs, $m)) {
                $val = trim($m[1]);
                if ($this->isSafeStyleValue($val)) {
                    $safeStyles[] = $property.': '.$val;
                }
            }
        }

        // Spacing styles (paragraph gaps, line spacing, indentation) with sane bounds.
        $spacingProperties = ['margin-top', 'margin-bottom', 'line-height', 'padding-left'];

        foreach ($spacingProperties as $property) {
            if (preg_match('/(?<![a-z-])'.preg_quote($property, '/').'\s*:\s*([^;"]+)/i', $attrs, $m)) {
                $val = trim($m[1]);
                if ($this->isSafeStyleValue($val) && $this->isSafeSpacingValue($val)) {
                    $safeStyles[] = $property.': '.$val;
                }
            }
        }

        return $safeStyles;
    }

    /**
     * Reject CSS values that could smuggle scripts, allowing only known color functions.
     */
    private function isSafeStyleValue(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $lower = strtolower($value);
        if (str_contains($lower, 'javascript:') || str_contains($lower, 'expression(') || str_contains($lower, 'url(')) {
            return false;
        }

        // Allow parentheses only for known color functions
        if (str_contains($value, '(') && ! preg_match('/^[^()]*(rgb|rgba|hsl|hsla)\([^()]*\)[^()]*$/i', $value)) {
            return false;
        }

        return true;
    }

    /**
     * Allow only small, sane spacing values so pasted content cannot inject huge gaps.
     */
    private function isSafeSpacingValue(string $value): bool
    {
        $v = strtolower(trim($value));

        // Unitless line-height (e.g. "1.5") capped at 3.
        if (preg_match('/^\d+(\.\d+)?$/', $v)) {
            return (float) $v <= 3;
        }

        if (! preg_match('/^(\d+(?:\.\d+)?)(px|pt|em|rem|%)$/', $v, $m)) {
            return false;
        }

        $num = (float) $m[1];

        return match ($m[2]) {
            'px', '%' => $num <= 100,
            'pt' => $num <= 75,
            'em', 'rem' => $num <= 6,
            default => false,
        };
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
