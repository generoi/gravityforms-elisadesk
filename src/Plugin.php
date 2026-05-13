<?php

namespace Genero\ElisaDesk;

final class Plugin
{
    public const VERSION = '1.0.0';

    public const SLUG = 'gravityforms-elisadesk';

    public const ENDPOINT_CONSTANT = 'ELISA_DESK_ENDPOINT';

    public const TIMEOUT_CONSTANT = 'ELISA_DESK_TIMEOUT';

    public const AUTH_CONSTANT = 'ELISA_DESK_AUTH';

    public const DEFAULT_TIMEOUT = 15;

    public string $file;

    public string $path;

    public string $url;

    protected static ?Plugin $instance = null;

    public static function getInstance(?string $file = null): self
    {
        if (self::$instance === null) {
            if ($file === null) {
                throw new \RuntimeException('Plugin must be bootstrapped with the plugin file path.');
            }
            self::$instance = new self($file);
        }

        return self::$instance;
    }

    private function __construct(string $file)
    {
        $this->file = $file;
        $this->path = untrailingslashit(plugin_dir_path($file));
        $this->url = untrailingslashit(plugin_dir_url($file));

        add_action('init', [$this, 'loadTextdomain'], 0);
        add_action('gform_loaded', [$this, 'registerAddOn'], 5);
        add_filter('upload_mimes', [$this, 'registerExtraMimes']);
        add_filter('wp_check_filetype_and_ext', [$this, 'detectExtraMimes'], 10, 4);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain(
            self::SLUG,
            false,
            dirname(plugin_basename($this->file)).'/resources/lang'
        );
    }

    /**
     * Teach WordPress about HEIC/HEIF. WP core's default upload allowlist
     * doesn't include them, which would otherwise reject iPhone photos at
     * upload time and confuse wp_check_filetype() downstream.
     *
     * @param  array<string, string>  $mimes
     * @return array<string, string>
     */
    public function registerExtraMimes(array $mimes): array
    {
        $mimes['heic'] = 'image/heic';
        $mimes['heif'] = 'image/heif';

        return $mimes;
    }

    /**
     * `wp_check_filetype_and_ext` rejects files whose content doesn't match a
     * known signature; HEIC/HEIF aren't in the core check. Force-accept the
     * declared type when the extension matches and the file is one of these.
     *
     * @param  array{ext?: string|false, type?: string|false, proper_filename?: string|false}  $checked
     * @return array{ext: string|false, type: string|false, proper_filename: string|false}
     */
    public function detectExtraMimes(array $checked, string $file, string $filename, $mimes): array
    {
        if (! empty($checked['ext']) && ! empty($checked['type'])) {
            return $checked + ['ext' => false, 'type' => false, 'proper_filename' => false];
        }

        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $explicit = ['heic' => 'image/heic', 'heif' => 'image/heif'];
        if (isset($explicit[$ext])) {
            return [
                'ext' => $ext,
                'type' => $explicit[$ext],
                'proper_filename' => $checked['proper_filename'] ?? false,
            ];
        }

        return $checked + ['ext' => false, 'type' => false, 'proper_filename' => false];
    }

    public function registerAddOn(): void
    {
        if (! class_exists('GFForms')) {
            return;
        }

        \GFForms::include_feed_addon_framework();
        \GFAddOn::register(AddOn::class);
    }

    public function endpoint(): ?string
    {
        return self::resolveSecret(self::ENDPOINT_CONSTANT);
    }

    public function authorization(): ?string
    {
        return self::resolveSecret(self::AUTH_CONSTANT);
    }

    public function timeout(): int
    {
        if (defined(self::TIMEOUT_CONSTANT)) {
            return (int) constant(self::TIMEOUT_CONSTANT);
        }

        $env = getenv(self::TIMEOUT_CONSTANT);
        if (is_string($env) && ctype_digit($env)) {
            return (int) $env;
        }

        return self::DEFAULT_TIMEOUT;
    }

    private static function resolveSecret(string $name): ?string
    {
        if (defined($name)) {
            $value = constant($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $env = getenv($name);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }
}
