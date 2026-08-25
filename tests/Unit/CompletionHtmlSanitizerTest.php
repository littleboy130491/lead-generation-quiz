<?php

namespace Tests\Unit;

use App\Services\CompletionHtmlSanitizer;
use Tests\TestCase;

class CompletionHtmlSanitizerTest extends TestCase
{
    public function test_it_keeps_the_safe_static_markup_subset_and_removes_executable_content(): void
    {
        $html = app(CompletionHtmlSanitizer::class)->sanitize('<h1 class="headline">All set</h1><p>We will email you shortly.</p><a href="https://example.test" target="_blank" onclick="alert(1)">Learn more</a><script>alert(1)</script><img src="javascript:alert(1)" onerror="alert(1)" alt="Example">');

        $this->assertStringContainsString('<h1 class="headline">All set</h1>', $html);
        $this->assertStringContainsString('<a href="https://example.test"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }
}
