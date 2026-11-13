<?php

declare(strict_types=1);

namespace App\Services\Sso;

use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;

/** Reject expensive XML transforms before the toolkit validates signatures. */
final class SamlPayloadGuard
{
    private const int MAX_PAYLOAD_BYTES = 1_048_576;

    private const array ALLOWED_TRANSFORMS = [
        'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
        XMLSecurityDSig::C14N,
        XMLSecurityDSig::C14N_COMMENTS,
        XMLSecurityDSig::EXC_C14N,
        XMLSecurityDSig::EXC_C14N_COMMENTS,
    ];

    public static function allows(mixed $encoded, bool $deflated = false): bool
    {
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return false;
        }

        $xml = base64_decode($encoded, true);
        if ($xml === false || $xml === '') {
            return false;
        }

        if ($deflated) {
            $inflated = @gzinflate($xml, self::MAX_PAYLOAD_BYTES);
            if ($inflated !== false) {
                $xml = $inflated;
            }
        }

        if (strlen($xml) > self::MAX_PAYLOAD_BYTES) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                return false;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $signatures = $document->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Signature');
        $references = $document->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Reference');
        $transforms = $document->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Transform');
        if ($signatures->length > 2 || $references->length > 2 || $transforms->length > 6) {
            return false;
        }

        foreach ($references as $reference) {
            $uri = $reference->getAttribute('URI');
            if (! str_starts_with($uri, '#') || strlen($uri) < 2) {
                return false;
            }
        }

        foreach ($transforms as $transform) {
            if (! in_array($transform->getAttribute('Algorithm'), self::ALLOWED_TRANSFORMS, true)) {
                return false;
            }
        }

        return true;
    }
}
