<?php

namespace Genero\ElisaDesk;

class Client
{
    /**
     * Sends a multipart/form-data POST with flat scalar fields and file parts.
     *
     * @param  array<string, string>  $fields  payload key => scalar text value
     * @param  list<array{name: string, path: string, filename?: string, contentType?: string}>  $files
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|\WP_Error
     */
    public function postMultipart(string $url, array $fields, array $files, int $timeout, array $headers = [])
    {
        $boundary = wp_generate_password(24, false);
        $body = new MultipartBody($boundary);

        foreach ($fields as $key => $value) {
            $body->field((string) $key, (string) $value);
        }

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || ! is_readable($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $body->file(
                (string) $file['name'],
                (string) ($file['filename'] ?? basename($path)),
                $contents,
                (string) ($file['contentType'] ?? self::mimeFor($path)),
            );
        }

        // Caller-provided Content-Type is always overridden — boundary is generated here.
        $headers['Content-Type'] = $body->contentType();

        return wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body->build(),
            'timeout' => $timeout,
        ]);
    }

    private static function mimeFor(string $path): string
    {
        if (function_exists('wp_check_filetype')) {
            $type = wp_check_filetype($path)['type'] ?? '';
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $explicit = [
            'heic' => 'image/heic',
            'heif' => 'image/heif',
        ];
        if (isset($explicit[$ext])) {
            return $explicit[$ext];
        }

        return 'application/octet-stream';
    }
}
