<?php

declare(strict_types=1);

namespace Genero\ElisaDesk\Tests;

use Genero\ElisaDesk\PayloadBuilder;
use PHPUnit\Framework\TestCase;

class PayloadBuilderTest extends TestCase
{
    // -- fields() --

    public function test_fields_merges_mappings_and_extras(): void
    {
        $out = PayloadBuilder::fields(
            ['name' => 'Anna', 'email' => 'a@b.fi'],
            ['title' => 'Reklamaatio: X', 'inquiry_type' => 'product_complaint', 'language' => 'fi', 'source_site' => 'snellman.fi']
        );

        $this->assertSame('Reklamaatio: X', $out['title']);
        $this->assertSame('product_complaint', $out['inquiry_type']);
        $this->assertSame('Anna', $out['name']);
        $this->assertSame('a@b.fi', $out['email']);
        $this->assertSame('fi', $out['language']);
        $this->assertSame('snellman.fi', $out['source_site']);
    }

    public function test_fields_drops_empty_values(): void
    {
        $out = PayloadBuilder::fields(
            ['name' => 'Anna', 'phone' => '', 'city' => 'Helsinki'],
            ['title' => 'T', 'inquiry_type' => 'feedback', 'language' => 'fi', 'source_site' => '']
        );

        $this->assertArrayHasKey('name', $out);
        $this->assertArrayNotHasKey('phone', $out);
        $this->assertArrayHasKey('city', $out);
        $this->assertArrayNotHasKey('source_site', $out, 'empty source_site should be dropped');
    }

    public function test_fields_drops_empty_keys(): void
    {
        $out = PayloadBuilder::fields(['' => 'ignored', 'name' => 'Anna'], []);

        $this->assertSame(['name' => 'Anna'], $out);
    }

    public function test_fields_admin_mapping_overrides_extra_on_key_collision(): void
    {
        // Reserved keys are filtered in AddOn before reaching here; if they
        // somehow leak through, the admin mapping wins. This is defense in
        // depth — admins shouldn't be able to silently shadow reserved keys.
        $out = PayloadBuilder::fields(
            ['title' => 'admin override'],
            ['title' => 'computed']
        );

        $this->assertSame('admin override', $out['title']);
    }

    // -- resolveInquiryType() --

    public function test_resolve_inquiry_type_fixed_complaint(): void
    {
        $this->assertSame(
            'product_complaint',
            PayloadBuilder::resolveInquiryType(PayloadBuilder::INQUIRY_COMPLAINT, '', '')
        );
    }

    public function test_resolve_inquiry_type_fixed_feedback(): void
    {
        $this->assertSame(
            'feedback',
            PayloadBuilder::resolveInquiryType(PayloadBuilder::INQUIRY_FEEDBACK, 'Reklamaatio', 'Reklamaatio')
        );
    }

    public function test_resolve_inquiry_type_derived_matches_single_value(): void
    {
        $this->assertSame(
            'product_complaint',
            PayloadBuilder::resolveInquiryType(PayloadBuilder::INQUIRY_DERIVED, 'Reklamaatio', 'Reklamaatio')
        );
    }

    public function test_resolve_inquiry_type_derived_matches_any_of_csv(): void
    {
        $this->assertSame(
            'product_complaint',
            PayloadBuilder::resolveInquiryType(PayloadBuilder::INQUIRY_DERIVED, 'Reklamation', 'Reklamaatio,Reklamation')
        );
    }

    public function test_resolve_inquiry_type_derived_trims_whitespace(): void
    {
        $this->assertSame(
            'product_complaint',
            PayloadBuilder::resolveInquiryType(PayloadBuilder::INQUIRY_DERIVED, 'Reklamation', ' Reklamaatio , Reklamation , ')
        );
    }

    public function test_resolve_inquiry_type_derived_falls_back_to_feedback_when_no_match(): void
    {
        $this->assertSame(
            'feedback',
            PayloadBuilder::resolveInquiryType(PayloadBuilder::INQUIRY_DERIVED, 'Muu palaute', 'Reklamaatio,Reklamation')
        );
    }

    public function test_resolve_inquiry_type_derived_with_empty_values_never_matches(): void
    {
        $this->assertSame(
            'feedback',
            PayloadBuilder::resolveInquiryType(PayloadBuilder::INQUIRY_DERIVED, 'anything', '')
        );
    }

    public function test_resolve_inquiry_type_unknown_mode_defaults_to_feedback(): void
    {
        $this->assertSame(
            'feedback',
            PayloadBuilder::resolveInquiryType('garbage', 'Reklamaatio', 'Reklamaatio')
        );
    }

    // -- collectAttachmentUrls() --

    public function test_collect_attachment_urls_from_json_array(): void
    {
        $this->assertSame(
            ['https://a/1.jpg', 'https://a/2.jpg'],
            PayloadBuilder::collectAttachmentUrls('["https://a/1.jpg","https://a/2.jpg"]')
        );
    }

    public function test_collect_attachment_urls_filters_empty_entries(): void
    {
        $this->assertSame(
            ['https://a/1.jpg'],
            PayloadBuilder::collectAttachmentUrls('["https://a/1.jpg",""]')
        );
    }

    public function test_collect_attachment_urls_from_plain_string(): void
    {
        $this->assertSame(
            ['https://a/single.jpg'],
            PayloadBuilder::collectAttachmentUrls('https://a/single.jpg')
        );
    }

    public function test_collect_attachment_urls_returns_empty_for_blank(): void
    {
        $this->assertSame([], PayloadBuilder::collectAttachmentUrls(''));
    }
}
