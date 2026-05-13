<?php

declare(strict_types=1);

namespace Genero\ElisaDesk\Tests;

use Genero\ElisaDesk\MultipartBody;
use PHPUnit\Framework\TestCase;

class MultipartBodyTest extends TestCase
{
    public function test_content_type_includes_boundary(): void
    {
        $body = new MultipartBody('abc123');
        $this->assertSame('multipart/form-data; boundary=abc123', $body->contentType());
    }

    public function test_empty_body_only_has_closing_boundary(): void
    {
        $built = (new MultipartBody('B'))->build();
        $this->assertSame("--B--\r\n", $built);
    }

    public function test_text_field_part(): void
    {
        $built = (new MultipartBody('B'))
            ->field('name', 'Anna')
            ->build();

        $expected = "--B\r\n"
            ."Content-Disposition: form-data; name=\"name\"\r\n"
            ."\r\n"
            ."Anna\r\n"
            ."--B--\r\n";

        $this->assertSame($expected, $built);
    }

    public function test_json_field_part_has_content_type(): void
    {
        $built = (new MultipartBody('B'))
            ->jsonField('payload', '{"a":1}')
            ->build();

        $this->assertStringContainsString('Content-Disposition: form-data; name="payload"', $built);
        $this->assertStringContainsString('Content-Type: application/json', $built);
        $this->assertStringContainsString('{"a":1}', $built);
    }

    public function test_file_part_includes_filename_and_mime(): void
    {
        $built = (new MultipartBody('B'))
            ->file('attachments[]', 'photo.jpg', 'BINARY', 'image/jpeg')
            ->build();

        $expected = "--B\r\n"
            ."Content-Disposition: form-data; name=\"attachments[]\"; filename=\"photo.jpg\"\r\n"
            ."Content-Type: image/jpeg\r\n"
            ."\r\n"
            ."BINARY\r\n"
            ."--B--\r\n";

        $this->assertSame($expected, $built);
    }

    public function test_multiple_files_share_boundary(): void
    {
        $built = (new MultipartBody('B'))
            ->jsonField('payload', '{}')
            ->file('attachments[]', 'a.jpg', 'A', 'image/jpeg')
            ->file('attachments[]', 'b.png', 'B', 'image/png')
            ->build();

        $this->assertSame(3, substr_count($built, "--B\r\n"));
        $this->assertSame(1, substr_count($built, "--B--\r\n"));
        $this->assertStringContainsString('filename="a.jpg"', $built);
        $this->assertStringContainsString('filename="b.png"', $built);
    }

    public function test_filename_with_path_separators_is_basenamed(): void
    {
        $this->assertSame('evil.jpg', MultipartBody::sanitizeFilename('../../etc/evil.jpg'));
        $this->assertSame('evil.jpg', MultipartBody::sanitizeFilename('C:\\Users\\evil.jpg'));
    }

    public function test_filename_with_crlf_is_stripped(): void
    {
        $sanitized = MultipartBody::sanitizeFilename("safe.jpg\r\nX-Injected: foo");
        $this->assertStringNotContainsString("\r", $sanitized);
        $this->assertStringNotContainsString("\n", $sanitized);
    }

    public function test_filename_with_quote_is_stripped(): void
    {
        $this->assertSame('cdr.jpg', MultipartBody::sanitizeFilename('cd"r.jpg'));
    }

    public function test_filename_empty_falls_back_to_placeholder(): void
    {
        $this->assertSame('file', MultipartBody::sanitizeFilename(''));
        $this->assertSame('file', MultipartBody::sanitizeFilename('///'));
    }

    public function test_field_name_with_quote_escaped_in_header(): void
    {
        $built = (new MultipartBody('B'))
            ->field('weird"name', 'v')
            ->build();

        $this->assertStringContainsString('name="weird\\"name"', $built);
    }

    public function test_field_name_with_crlf_cannot_inject_extra_headers(): void
    {
        $built = (new MultipartBody('B'))
            ->field("evil\r\nX-Inject: yes", 'v')
            ->build();

        // CRLF gets stripped, so the injection collapses into one field name
        // ("evilX-Inject: yes") and no real extra header is emitted.
        $disposition = "Content-Disposition: form-data; name=\"evilX-Inject: yes\"\r\n";
        $this->assertStringContainsString($disposition, $built);
        // Exactly one Content-Disposition header per part — no second one snuck in.
        $this->assertSame(1, substr_count($built, 'Content-Disposition:'));
    }

    public function test_binary_content_passes_through_unchanged(): void
    {
        // Non-text bytes — verify nothing is mangled by string escaping logic.
        $binary = "\x00\x01\xff\xfe random \xde\xad";
        $built = (new MultipartBody('B'))
            ->file('attachments[]', 'b.bin', $binary, 'application/octet-stream')
            ->build();

        // The exact binary string should appear between header CRLFs and the closing boundary.
        $this->assertStringContainsString("\r\n\r\n".$binary."\r\n--B--", $built);
    }
}
