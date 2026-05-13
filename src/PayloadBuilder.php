<?php

namespace Genero\ElisaDesk;

/**
 * Pure helpers for building the Elisa Desk multipart payload. The AddOn does
 * the GF-side resolution (merge tags, field value lookup, file collection)
 * and passes already-resolved primitives in here.
 */
class PayloadBuilder
{
    public const INQUIRY_FEEDBACK = 'feedback';

    public const INQUIRY_COMPLAINT = 'product_complaint';

    public const INQUIRY_DERIVED = 'derived';

    /**
     * Assembles the flat scalar payload that will be POSTed as multipart text parts.
     * Empty values are dropped so the receiving end never sees blank fields.
     *
     * @param  array<string, string>  $mappings  payload key => resolved trimmed value
     * @param  array<string, string>  $extra  reserved/computed fields (title, inquiry_type, language, source_site)
     * @return array<string, string>
     */
    public static function fields(array $mappings, array $extra): array
    {
        $out = [];
        foreach ($extra as $key => $value) {
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        foreach ($mappings as $key => $value) {
            if ($key === '' || $value === '') {
                continue;
            }
            // Caller-provided values trump extras only if a collision happens —
            // shouldn't, since admins can't map reserved keys, but be safe.
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Resolves the final `inquiry_type` literal sent to Elisa Desk based on the
     * feed's mode and (when derived) the value of the inquiry-type source field.
     *
     * @param  string  $mode  one of INQUIRY_FEEDBACK | INQUIRY_COMPLAINT | INQUIRY_DERIVED
     * @param  string  $sourceValue  the value of the GF field admin chose as inquiry_type source
     * @param  string  $complaintValues  comma-separated list of values that should map to product_complaint
     */
    public static function resolveInquiryType(string $mode, string $sourceValue, string $complaintValues): string
    {
        if ($mode === self::INQUIRY_COMPLAINT) {
            return self::INQUIRY_COMPLAINT;
        }
        if ($mode !== self::INQUIRY_DERIVED) {
            return self::INQUIRY_FEEDBACK;
        }

        $matches = array_values(array_filter(
            array_map('trim', explode(',', $complaintValues)),
            static fn (string $v) => $v !== ''
        ));
        if ($matches === []) {
            return self::INQUIRY_FEEDBACK;
        }

        return in_array($sourceValue, $matches, true)
            ? self::INQUIRY_COMPLAINT
            : self::INQUIRY_FEEDBACK;
    }

    /**
     * Decodes a Gravity Forms `fileupload` field value, which is either a JSON
     * array (multi-file) or a single URL string. Empty/falsy URLs are dropped.
     *
     * @return list<string>
     */
    public static function collectAttachmentUrls(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return [$value];
    }
}
