<?php

namespace Genero\ElisaDesk;

class AddOn extends \GFFeedAddOn
{
    protected $_version = Plugin::VERSION;

    protected $_min_gravityforms_version = '2.5';

    protected $_slug = Plugin::SLUG;

    protected $_path = 'gravityforms-elisadesk/gravityforms-elisadesk.php';

    /** @var string */
    protected $_full_path;

    protected $_title = 'Elisa Desk for Gravity Forms';

    protected $_short_title = 'Elisa Desk';

    protected $_capabilities = ['gravityforms_elisadesk', 'gravityforms_elisadesk_uninstall'];

    protected $_capabilities_form_settings = 'gravityforms_elisadesk';

    protected $_capabilities_uninstall = 'gravityforms_elisadesk_uninstall';

    /** Enables GF's async queue + retry pipeline (see gform_max_async_feed_attempts). */
    protected $_async_feed_processing = true;

    /**
     * Payload keys that the plugin always computes and writes itself; admins
     * can't map a GF field to these via the field-mapping UI.
     *
     * @var list<string>
     */
    private const RESERVED_KEYS = ['title', 'inquiry_type', 'language', 'source_site'];

    private static ?AddOn $_instance = null;

    public static function get_instance(): self
    {
        if (self::$_instance === null) {
            self::$_instance = new self;
        }

        return self::$_instance;
    }

    public function __construct()
    {
        $this->_full_path = Plugin::getInstance()->file;
        parent::__construct();
    }

    public function plugin_settings_fields(): array
    {
        return [
            [
                'title' => __('Connection', 'gravityforms-elisadesk'),
                'description' => __('Site-wide connection settings — applied to every Elisa Desk feed on this site.', 'gravityforms-elisadesk'),
                'fields' => [
                    [
                        'name' => 'endpoint',
                        'label' => __('Endpoint URL', 'gravityforms-elisadesk'),
                        'type' => 'text',
                        'class' => 'medium',
                        'tooltip' => __('Leave empty to fall back to the ELISA_DESK_ENDPOINT constant or env var.', 'gravityforms-elisadesk'),
                    ],
                    [
                        'name' => 'authorization',
                        'label' => __('Authorization header', 'gravityforms-elisadesk'),
                        'type' => 'text',
                        'class' => 'medium',
                        'tooltip' => __('Optional value sent as the Authorization HTTP header (e.g. "Bearer …"). Leave empty to fall back to the ELISA_DESK_AUTH constant or env var. Prefer the constant/env var over storing secrets in the database.', 'gravityforms-elisadesk'),
                    ],
                    [
                        'name' => 'timeout',
                        'label' => __('Timeout (seconds)', 'gravityforms-elisadesk'),
                        'type' => 'text',
                        'class' => 'small',
                        'tooltip' => __('HTTP request timeout. Leave empty to fall back to the ELISA_DESK_TIMEOUT constant, env var, or 15 seconds default.', 'gravityforms-elisadesk'),
                    ],
                    [
                        'name' => 'sourceSite',
                        'label' => __('Source site', 'gravityforms-elisadesk'),
                        'type' => 'text',
                        'class' => 'medium',
                        'tooltip' => __('Value sent as the "source_site" payload field for every feed on this site. Leave empty to default to the host of home_url().', 'gravityforms-elisadesk'),
                    ],
                ],
            ],
            [
                'title' => __('Logging', 'gravityforms-elisadesk'),
                'fields' => [
                    [
                        'name' => 'verboseLogging',
                        'label' => __('Verbose payload logging', 'gravityforms-elisadesk'),
                        'type' => 'checkbox',
                        'choices' => [[
                            'name' => 'verboseLogging',
                            'label' => __('Log every field value and attached file path to the GF debug log.', 'gravityforms-elisadesk'),
                        ]],
                        'tooltip' => __('When enabled, every Elisa Desk submission writes its full payload (including PII like names, emails, and phone numbers) to the Gravity Forms debug log. Useful for debugging integration issues — turn off in production.', 'gravityforms-elisadesk'),
                    ],
                ],
            ],
        ];
    }

    public function feed_settings_fields(): array
    {
        return [
            [
                'title' => __('Feed Settings', 'gravityforms-elisadesk'),
                'fields' => [
                    [
                        'name' => 'feedName',
                        'label' => __('Name', 'gravityforms-elisadesk'),
                        'type' => 'text',
                        'required' => true,
                        'class' => 'medium',
                        'tooltip' => __('Internal name to identify this feed.', 'gravityforms-elisadesk'),
                    ],
                    [
                        'name' => 'titleTemplate',
                        'label' => __('Title template', 'gravityforms-elisadesk'),
                        'type' => 'text',
                        'required' => true,
                        'class' => 'medium merge-tag-support mt-position-right',
                        'tooltip' => __('Used as the "title" payload field. Supports Gravity Forms merge tags, e.g. "Reklamaatio: {Tuote:16:value}" (the :value modifier preserves the stored value for select fields) or "Palaute: {Nimi:2}".', 'gravityforms-elisadesk'),
                    ],
                ],
            ],
            [
                'title' => __('Inquiry Type', 'gravityforms-elisadesk'),
                'fields' => [
                    [
                        'name' => 'inquiryType',
                        'label' => __('Inquiry Type', 'gravityforms-elisadesk'),
                        'type' => 'select',
                        'default_value' => PayloadBuilder::INQUIRY_FEEDBACK,
                        'choices' => [
                            ['label' => __('Feedback', 'gravityforms-elisadesk'), 'value' => PayloadBuilder::INQUIRY_FEEDBACK],
                            ['label' => __('Product complaint', 'gravityforms-elisadesk'), 'value' => PayloadBuilder::INQUIRY_COMPLAINT],
                            ['label' => __('Derive from mapped field', 'gravityforms-elisadesk'), 'value' => PayloadBuilder::INQUIRY_DERIVED],
                        ],
                        'tooltip' => __('Choose a fixed inquiry_type, or derive it from the mapped "inquiry_type" key\'s GF field value (matching one of the "Complaint values" below).', 'gravityforms-elisadesk'),
                    ],
                    [
                        'name' => 'complaintValue',
                        'label' => __('Complaint values', 'gravityforms-elisadesk'),
                        'type' => 'text',
                        'class' => 'medium',
                        'default_value' => 'Reklamaatio',
                        'tooltip' => __('When inquiry_type is derived, an entry is treated as product_complaint if the mapped "inquiry_type" field matches one of these values. Multiple values can be separated by commas, e.g. "Reklamaatio,Reklamation".', 'gravityforms-elisadesk'),
                    ],
                ],
            ],
            [
                'title' => __('Field Mapping', 'gravityforms-elisadesk'),
                'description' => __('Map Elisa Desk payload keys to Gravity Forms fields. File upload fields are automatically sent as multipart attachments under the chosen key.', 'gravityforms-elisadesk'),
                'fields' => [
                    [
                        'name' => 'fields',
                        'label' => __('Fields', 'gravityforms-elisadesk'),
                        'type' => 'dynamic_field_map',
                        'enable_custom_key' => true,
                        'key_choices' => $this->keyChoices(),
                    ],
                ],
            ],
            [
                'title' => __('Conditional Logic', 'gravityforms-elisadesk'),
                'fields' => [
                    [
                        'name' => 'conditionalLogic',
                        'label' => __('Enable Condition', 'gravityforms-elisadesk'),
                        'type' => 'feed_condition',
                        'checkbox_label' => __('Process this feed if', 'gravityforms-elisadesk'),
                        'instructions' => __('Send to Elisa Desk only if the following condition matches:', 'gravityforms-elisadesk'),
                    ],
                ],
            ],
        ];
    }

    public function feed_list_columns(): array
    {
        return [
            'feedName' => __('Name', 'gravityforms-elisadesk'),
            'inquiryType' => __('Inquiry Type', 'gravityforms-elisadesk'),
        ];
    }

    public function get_column_value_inquiryType(array $feed): string
    {
        return (string) rgars($feed, 'meta/inquiryType');
    }

    /**
     * @param  array<string, mixed>  $feed
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $form
     * @return bool|\WP_Error|null
     */
    public function process_feed($feed, $entry, $form)
    {
        $entryId = (string) rgar($entry, 'id');
        $endpoint = $this->resolveEndpoint($feed, $entry, $form);

        if ($endpoint === null) {
            $message = __('Endpoint URL is not configured.', 'gravityforms-elisadesk');
            $this->add_feed_error($message, $feed, $entry, $form);
            $this->logDebug(sprintf('entry %s: endpoint not configured', $entryId));

            return false;
        }

        if ($endpoint === false) {
            $message = __('Endpoint URL failed validation (not a permitted external URL).', 'gravityforms-elisadesk');
            $this->add_feed_error($message, $feed, $entry, $form);
            $this->logError(sprintf('entry %s: endpoint rejected by validator', $entryId));

            return false;
        }

        [$fields, $files] = $this->buildPayload($feed, $entry, $form);

        $fields = apply_filters('genero/elisa_desk/fields', $fields, $entry, $form, $feed);
        $files = apply_filters('genero/elisa_desk/files', $files, $entry, $form, $feed);

        $timeout = $this->resolveTimeout();
        $headers = $this->requestHeaders($feed, $entry, $form);

        $this->logDebug(sprintf(
            'entry %s: POST %s (inquiry_type=%s, files=%d)',
            $entryId,
            $endpoint,
            (string) ($fields['inquiry_type'] ?? ''),
            count($files)
        ));

        if ($this->get_plugin_setting('verboseLogging')) {
            $this->logDebug(sprintf(
                'entry %s: payload fields = %s',
                $entryId,
                wp_json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
            if ($files !== []) {
                $names = array_map(static fn ($f) => $f['name'].'='.basename($f['path']), $files);
                $this->logDebug(sprintf('entry %s: payload files = %s', $entryId, implode(', ', $names)));
            }
        }

        $response = (new Client)->postMultipart($endpoint, $fields, $files, $timeout, $headers);

        if (is_wp_error($response)) {
            $message = sprintf(
                /* translators: %s: error message */
                __('Request failed: %s', 'gravityforms-elisadesk'),
                $response->get_error_message()
            );
            $this->add_feed_error($message, $feed, $entry, $form);
            $this->logError(sprintf('entry %s: %s', $entryId, $message));

            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $body = (string) wp_remote_retrieve_body($response);
            $truncated = mb_strlen($body) > 500 ? mb_substr($body, 0, 500).'…' : $body;
            $message = sprintf(
                /* translators: 1: HTTP status code, 2: response body */
                __('HTTP %1$d response: %2$s', 'gravityforms-elisadesk'),
                $code,
                $truncated
            );
            $this->add_feed_error($message, $feed, $entry, $form);
            $this->logError(sprintf('entry %s: %s', $entryId, $message));

            if ($code >= 500 || $code === 429) {
                return new \WP_Error('elisa_desk_transient', $message, ['status' => $code]);
            }

            return false;
        }

        $this->logDebug(sprintf('entry %s: HTTP %d OK', $entryId, $code));
        $this->addSuccessNote($entry, $code);

        return true;
    }

    /**
     * Builds the multipart payload split into scalar text fields and binary
     * file parts.
     *
     * @param  array<string, mixed>  $feed
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $form
     * @return array{0: array<string, string>, 1: list<array{name: string, path: string, filename: string, contentType: string}>}
     */
    private function buildPayload(array $feed, array $entry, array $form): array
    {
        $mappings = \GFAddOn::get_dynamic_field_map_fields($feed, 'fields');

        $fields = [];
        $files = [];
        $inquiryTypeRawValue = '';

        foreach ($mappings as $key => $fieldId) {
            $key = trim((string) $key);
            $fieldId = (string) $fieldId;
            if ($key === '' || $fieldId === '' || in_array($key, self::RESERVED_KEYS, true)) {
                continue;
            }

            $gfField = self::findField($form, $fieldId);
            if ($gfField !== null && self::isFileField($gfField)) {
                $urls = PayloadBuilder::collectAttachmentUrls((string) $this->get_field_value($form, $entry, $fieldId));
                foreach (self::resolveLocalFiles($urls) as $path) {
                    $files[] = [
                        'name' => $key,
                        'path' => $path,
                        'filename' => basename($path),
                        'contentType' => self::mimeFor($path),
                    ];
                }

                continue;
            }

            $value = trim((string) $this->get_field_value($form, $entry, $fieldId));
            if ($key === 'inquiry_type') {
                // Inquiry type is computed; the raw mapped value only feeds the derived mode.
                $inquiryTypeRawValue = $value;

                continue;
            }
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        // esc_html=false: we're sending raw text in a multipart text part, not
        // HTML. Otherwise a customer named "O'Brien & Sons" would arrive as
        // "O&#039;Brien &amp; Sons" in the Elisa Desk title.
        $title = trim((string) \GFCommon::replace_variables(
            (string) rgars($feed, 'meta/titleTemplate'),
            $form,
            $entry,
            false,
            false,
            false,
            'text'
        ));

        $extra = [
            'title' => $title,
            'inquiry_type' => PayloadBuilder::resolveInquiryType(
                (string) (rgars($feed, 'meta/inquiryType') ?: PayloadBuilder::INQUIRY_FEEDBACK),
                $inquiryTypeRawValue,
                (string) rgars($feed, 'meta/complaintValue'),
            ),
            'language' => self::currentLanguage(),
            'source_site' => $this->resolveSourceSite(),
        ];

        return [PayloadBuilder::fields($fields, $extra), $files];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function keyChoices(): array
    {
        // Suggestions only — admins can type their own key via the "Add custom"
        // entry the dynamic_field_map renders when enable_custom_key is true.
        $defaults = [
            'name' => __('Name', 'gravityforms-elisadesk'),
            'email' => __('Email', 'gravityforms-elisadesk'),
            'phone' => __('Phone', 'gravityforms-elisadesk'),
            'street_address' => __('Street address', 'gravityforms-elisadesk'),
            'postal_code' => __('Postal code', 'gravityforms-elisadesk'),
            'city' => __('City', 'gravityforms-elisadesk'),
            'content' => __('Content (message text)', 'gravityforms-elisadesk'),
            'inquiry_type' => __('Inquiry type source field', 'gravityforms-elisadesk'),
            'product' => __('Product', 'gravityforms-elisadesk'),
            'product_weight' => __('Product weight', 'gravityforms-elisadesk'),
            'purchase_store' => __('Purchase store', 'gravityforms-elisadesk'),
            'purchase_date' => __('Purchase date', 'gravityforms-elisadesk'),
            'product_best_before' => __('Best before', 'gravityforms-elisadesk'),
            'attachment' => __('Attachment (file upload)', 'gravityforms-elisadesk'),
        ];

        $choices = [];
        foreach ($defaults as $value => $label) {
            $choices[] = ['label' => $label, 'value' => $value];
        }

        return $choices;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private static function findField(array $form, string $fieldId): ?\GF_Field
    {
        if (! class_exists('GFAPI')) {
            return null;
        }
        $field = \GFAPI::get_field($form, $fieldId);

        return $field instanceof \GF_Field ? $field : null;
    }

    private static function isFileField(\GF_Field $field): bool
    {
        return ($field->type ?? '') === 'fileupload' || ($field->type ?? '') === 'post_image';
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(array $feed, array $entry, array $form): array
    {
        $headers = [];

        $auth = $this->resolveAuthorization();
        if ($auth !== '') {
            $headers['Authorization'] = $auth;
        }

        $formId = (string) rgar($form, 'id');
        $entryId = (string) rgar($entry, 'id');
        if ($formId !== '' && $entryId !== '') {
            // Include source_site to keep the key unique across snellman.fi /
            // kokkikartano.fi / panini.fi if they share a single endpoint —
            // otherwise form 43 entry 1234 on each site would collide.
            $headers['Idempotency-Key'] = sprintf(
                '%s-gf-%s-entry-%s',
                $this->resolveSourceSite() ?: 'unknown',
                $formId,
                $entryId
            );
        }

        /** @var array<string, string> $headers */
        $headers = apply_filters('genero/elisa_desk/request_headers', $headers, $feed, $entry, $form);

        return $headers;
    }

    private function resolveAuthorization(): string
    {
        $setting = trim((string) $this->get_plugin_setting('authorization'));
        if ($setting !== '') {
            return $setting;
        }

        return (string) (Plugin::getInstance()->authorization() ?? '');
    }

    private function resolveSourceSite(): string
    {
        $setting = trim((string) $this->get_plugin_setting('sourceSite'));
        if ($setting !== '') {
            return $setting;
        }

        return (string) (parse_url((string) home_url(), PHP_URL_HOST) ?: '');
    }

    private function addSuccessNote(array $entry, int $code): void
    {
        $entryId = (int) rgar($entry, 'id');
        if ($entryId <= 0 || ! class_exists('GFAPI')) {
            return;
        }

        \GFAPI::add_note(
            $entryId,
            0,
            __('Elisa Desk', 'gravityforms-elisadesk'),
            sprintf(
                /* translators: %d: HTTP status code */
                __('Sent to Elisa Desk (HTTP %d).', 'gravityforms-elisadesk'),
                $code
            ),
            Plugin::SLUG,
            'success' // @phpstan-ignore argument.type (stub phpdoc wrongly types $sub_type as null; runtime sanitize_text_fields it)
        );
    }

    private function logDebug(string $message): void
    {
        if (class_exists('GFCommon')) {
            \GFCommon::log_debug(sprintf('Elisa Desk: %s', $message));
        }
    }

    private function logError(string $message): void
    {
        if (class_exists('GFCommon')) {
            \GFCommon::log_error(sprintf('Elisa Desk: %s', $message));
        }
    }

    private static function currentLanguage(): string
    {
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language('slug');
            if (is_string($lang) && $lang !== '') {
                return $lang;
            }
        }

        return substr((string) get_locale(), 0, 2);
    }

    /**
     * Maps GF upload URLs to local file paths under the WordPress uploads dir.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private static function resolveLocalFiles(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $upload = wp_get_upload_dir();
        $baseurl = isset($upload['baseurl']) ? (string) $upload['baseurl'] : '';
        $basedir = isset($upload['basedir']) ? (string) $upload['basedir'] : '';
        if ($baseurl === '' || $basedir === '') {
            return [];
        }

        $files = [];
        foreach ($urls as $url) {
            $url = (string) $url;
            if ($url === '' || strpos($url, $baseurl) !== 0) {
                continue;
            }
            $path = $basedir.substr($url, strlen($baseurl));
            if (is_readable($path) && is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private static function mimeFor(string $path): string
    {
        if (function_exists('wp_check_filetype')) {
            $type = wp_check_filetype($path)['type'] ?? '';
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        // WordPress's default allowed-mime list doesn't include HEIC/HEIF,
        // so wp_check_filetype() returns empty for iPhone photos. Anssi
        // explicitly asked for HEIC support — hand it the right type so the
        // receiving end can dispatch properly instead of seeing octet-stream.
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

    /**
     * @param  array<string, mixed>  $feed
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $form
     * @return string|null|false URL string if usable, null if unconfigured, false if rejected by validator.
     */
    private function resolveEndpoint(array $feed = [], array $entry = [], array $form = [])
    {
        $endpoint = trim((string) $this->get_plugin_setting('endpoint'));
        if ($endpoint === '') {
            $endpoint = (string) (Plugin::getInstance()->endpoint() ?? '');
        }

        // Lets a site route to per-language / per-environment endpoints —
        // e.g. an add_filter() in functions.php that returns a different URL
        // based on pll_current_language() or feed metadata.
        $endpoint = (string) apply_filters('genero/elisa_desk/endpoint', $endpoint, $feed, $entry, $form);

        if ($endpoint === '') {
            return null;
        }

        return self::validateEndpoint($endpoint);
    }

    /**
     * @return string|false
     */
    private static function validateEndpoint(string $url)
    {
        $validated = wp_http_validate_url($url);
        if (! is_string($validated)) {
            return false;
        }

        $allowPrivate = (bool) apply_filters('genero/elisa_desk/allow_private_endpoint', false, $validated);
        if ($allowPrivate) {
            return $validated;
        }

        $host = parse_url($validated, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        if (self::hostIsPrivate($host)) {
            return false;
        }

        return $validated;
    }

    private static function hostIsPrivate(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return ! (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function resolveTimeout(): int
    {
        $setting = trim((string) $this->get_plugin_setting('timeout'));
        if ($setting !== '' && ctype_digit($setting)) {
            return (int) $setting;
        }

        return Plugin::getInstance()->timeout();
    }
}
