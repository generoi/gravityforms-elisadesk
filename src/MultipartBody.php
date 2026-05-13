<?php

namespace Genero\ElisaDesk;

/**
 * Builds an RFC 7578 multipart/form-data body. Pure: takes already-resolved
 * file contents as strings; the caller is responsible for reading from disk
 * and choosing safe filenames.
 */
class MultipartBody
{
    public const CRLF = "\r\n";

    /** @var list<array{name: string, value: string, filename: ?string, contentType: ?string}> */
    private array $parts = [];

    public function __construct(private string $boundary) {}

    public function field(string $name, string $value): self
    {
        $this->parts[] = [
            'name' => $name,
            'value' => $value,
            'filename' => null,
            'contentType' => null,
        ];

        return $this;
    }

    public function jsonField(string $name, string $json): self
    {
        $this->parts[] = [
            'name' => $name,
            'value' => $json,
            'filename' => null,
            'contentType' => 'application/json',
        ];

        return $this;
    }

    public function file(string $name, string $filename, string $contents, string $contentType): self
    {
        $this->parts[] = [
            'name' => $name,
            'value' => $contents,
            'filename' => self::sanitizeFilename($filename),
            'contentType' => $contentType,
        ];

        return $this;
    }

    public function contentType(): string
    {
        return sprintf('multipart/form-data; boundary=%s', $this->boundary);
    }

    public function build(): string
    {
        $out = '';
        foreach ($this->parts as $part) {
            $out .= '--'.$this->boundary.self::CRLF;

            if ($part['filename'] !== null) {
                $out .= sprintf(
                    'Content-Disposition: form-data; name="%s"; filename="%s"%s',
                    self::escapeQuoted($part['name']),
                    self::escapeQuoted($part['filename']),
                    self::CRLF
                );
            } else {
                $out .= sprintf(
                    'Content-Disposition: form-data; name="%s"%s',
                    self::escapeQuoted($part['name']),
                    self::CRLF
                );
            }

            if ($part['contentType'] !== null) {
                $out .= 'Content-Type: '.$part['contentType'].self::CRLF;
            }

            $out .= self::CRLF.$part['value'].self::CRLF;
        }

        return $out.'--'.$this->boundary.'--'.self::CRLF;
    }

    /**
     * Strips path traversal and CRLF injection. Falls back to a placeholder
     * if the resulting name is empty (e.g. caller passed only path separators).
     */
    public static function sanitizeFilename(string $filename): string
    {
        $clean = basename(str_replace(['\\', "\r", "\n", '"'], ['/', '', '', ''], $filename));

        return $clean !== '' ? $clean : 'file';
    }

    private static function escapeQuoted(string $value): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
    }
}
