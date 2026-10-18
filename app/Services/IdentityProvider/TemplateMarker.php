<?php

namespace App\Services\IdentityProvider;

/**
 * The template marker legacy stores in `mailbox.attributes.attribute_hash` (research R6): the
 * SHA-1 of the JSON encoding (PHP's default flags) of the template's `attributes` column text, as
 * mailbox('add' | 'edit', 'mailbox_from_template') computes it. A sign-in through the provider
 * updates a mailbox from its mapped template only when the marker or the display name changed.
 */
final class TemplateMarker
{
    public static function for(string $attributesText): string
    {
        return sha1((string) json_encode($attributesText));
    }
}
