<?php

declare(strict_types=1);

namespace Forgeline\Infra;

/**
 * Verifies X-Sellaxis-Signature: sha256=<hex>, an HMAC-SHA256 of the RAW
 * request body using the shared WEBHOOK_SECRET. Per the stub: "Verify
 * before trusting anything in the body." This check happens before the
 * payload is parsed as JSON, let alone before any dedup/processing logic
 * sees it -- an invalid signature means the request is rejected outright,
 * with no partial trust extended to any field in it.
 */
final class WebhookSignatureVerifier
{
    public function __construct(private string $secret) {}

    public function verify(string $rawBody, ?string $headerValue): bool
    {
        if ($headerValue === null || !str_starts_with($headerValue, 'sha256=')) {
            return false;
        }
        $provided = substr($headerValue, 7);
        $expected = hash_hmac('sha256', $rawBody, $this->secret);
        return hash_equals($expected, $provided);
    }
}
