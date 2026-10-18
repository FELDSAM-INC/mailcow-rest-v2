<?php

namespace Tests\Unit\IdentityProvider;

use App\Services\IdentityProvider\TemplateMarker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T011 (research R6): the template marker stored in `mailbox.attributes.attribute_hash` is exactly
 * what legacy computes, so that legacy and this API detect the same template changes.
 */
class TemplateMarkerTest extends TestCase
{
    /**
     * Verbatim copy of legacy's expression, data/web/inc/functions.mailbox.inc.php (2026-09a),
     * mailbox('add' | 'edit', 'mailbox_from_template'):
     *
     *     $attribute_hash = sha1(json_encode($mbox_template_data["attributes"]));
     *
     * where $mbox_template_data is the `templates` row and "attributes" its JSON column as text.
     */
    private static function legacy(string $attributes): string
    {
        $mbox_template_data = ['attributes' => $attributes];

        return sha1(json_encode($mbox_template_data['attributes']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function attributes(): iterable
    {
        yield 'plain' => ['{"quota":1073741824,"active":1}'];
        yield 'slashes' => ['{"tags":["a/b"],"rl_frame":"s"}'];
        yield 'non-ascii' => ['{"tags":["Účetní","日本"],"quota":0}'];
        yield 'nested json' => ['{"acl":{"spam_alias":1},"tags":[{"x":"\"quoted\""}]}'];
        yield 'empty' => [''];
    }

    #[DataProvider('attributes')]
    public function test_the_marker_equals_legacys_attribute_hash(string $attributes): void
    {
        $this->assertSame(self::legacy($attributes), TemplateMarker::for($attributes));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', TemplateMarker::for($attributes));
    }
}
