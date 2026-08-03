<?php

namespace Tests\Unit\Http\Traits;

use App\Http\Traits\SanitizesHtmlInput;
use Illuminate\Foundation\Http\FormRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SanitizesHtmlInputTest extends TestCase
{
    /**
     * Sanitize a single field's HTML through the trait and return the result.
     */
    private function sanitize(string $html): string
    {
        $request = new class extends FormRequest
        {
            use SanitizesHtmlInput;

            public function run(): void
            {
                $this->sanitizeHtmlFields();
            }
        };

        $request->merge(['performance_description' => $html]);
        $request->run();

        return (string) $request->input('performance_description');
    }

    #[Test]
    public function it_preserves_whitelisted_formatting_styles(): void
    {
        $result = $this->sanitize(
            '<span style="font-weight: bold; font-style: italic; text-decoration: underline; '
            .'color: #ff0000; background-color: #ffff00; font-size: 18px;">Hello</span>'
        );

        $this->assertStringContainsString('font-weight: bold', $result);
        $this->assertStringContainsString('font-style: italic', $result);
        $this->assertStringContainsString('text-decoration: underline', $result);
        $this->assertStringContainsString('color: #ff0000', $result);
        $this->assertStringContainsString('background-color: #ffff00', $result);
        $this->assertStringContainsString('font-size: 18px', $result);
    }

    #[Test]
    public function it_preserves_text_alignment_on_paragraphs(): void
    {
        $result = $this->sanitize('<p style="text-align: center; margin: 40px;">Centered</p>');

        $this->assertStringContainsString('text-align: center', $result);
        $this->assertStringNotContainsString('margin', $result);
    }

    #[Test]
    public function it_strips_font_family_and_layout_styles(): void
    {
        $result = $this->sanitize('<span style="font-family: Calibri; letter-spacing: 2px; color: #123456;">Text</span>');

        $this->assertStringNotContainsString('font-family', $result);
        $this->assertStringNotContainsString('letter-spacing', $result);
        $this->assertStringContainsString('color: #123456', $result);
    }

    #[Test]
    public function it_does_not_confuse_color_with_background_color(): void
    {
        $result = $this->sanitize('<span style="background-color: #abcdef;">Text</span>');

        $this->assertStringContainsString('background-color: #abcdef', $result);
        $this->assertStringNotContainsString('; color: #abcdef', $result);
    }

    #[Test]
    public function it_rejects_dangerous_style_values(): void
    {
        $result = $this->sanitize('<span style="color: red; background-color: url(javascript:alert(1));">x</span>');

        $this->assertStringContainsString('color: red', $result);
        $this->assertStringNotContainsString('url(', $result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    #[Test]
    public function it_strips_event_handlers_and_scripts(): void
    {
        $result = $this->sanitize('<p onclick="steal()">Hi</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function it_preserves_list_style_type_and_href(): void
    {
        $result = $this->sanitize('<ol style="list-style-type: lower-alpha;"><li>One</li></ol>');
        $this->assertStringContainsString('list-style-type: lower-alpha', $result);

        $link = $this->sanitize('<a href="https://example.com" style="color: blue;">link</a>');
        $this->assertStringContainsString('href="https://example.com"', $link);
        $this->assertStringContainsString('color: blue', $link);
    }

    #[Test]
    public function it_preserves_normal_font_weight_on_bold_wrapper(): void
    {
        $result = $this->sanitize('<b style="font-weight: normal;">Not bold</b>');

        $this->assertStringContainsString('font-weight: normal', $result);
    }

    #[Test]
    public function it_preserves_reasonable_paragraph_spacing(): void
    {
        $result = $this->sanitize(
            '<p style="margin-top: 12px; margin-bottom: 12px; line-height: 1.5; padding-left: 24px;">Spaced</p>'
        );

        $this->assertStringContainsString('margin-top: 12px', $result);
        $this->assertStringContainsString('margin-bottom: 12px', $result);
        $this->assertStringContainsString('line-height: 1.5', $result);
        $this->assertStringContainsString('padding-left: 24px', $result);
    }

    #[Test]
    public function it_strips_excessive_spacing_values(): void
    {
        $result = $this->sanitize('<p style="margin-top: 9999px; line-height: 50;">Runaway</p>');

        $this->assertStringNotContainsString('margin-top', $result);
        $this->assertStringNotContainsString('line-height', $result);
    }
}
