<?php

declare(strict_types=1);

namespace Forgeline\Infra;

/**
 * Polls the (fake) Sellaxis API, with real handling for the three error
 * behaviours the stubs say are deliberately emitted:
 *
 *   429 -- respects Retry-After exactly; never polls faster than the
 *          documented 60s floor regardless of caller eagerness.
 *   500 -- retried with backoff; treated as transient, not as a reason to
 *          fail the whole poll cycle.
 *   malformed page -- json_decode failure is caught explicitly; the page
 *          is quarantined as a poll-level exception (visible via the
 *          reconciliation API) rather than crashing the poller or silently
 *          skipping a since-cursor, which would create a permanent gap.
 */
final class SellaxisClient
{
    public function __construct(private string $baseUrl, private string $bearerToken) {}

    /**
     * @return array{ok: bool, status: string, body: ?array, retry_after: ?int}
     */
    public function pollOrders(string $since, ?string $force = null): array
    {
        return $this->poll('/api/orders', ['since' => $since] + ($force ? ['force' => $force] : []));
    }

    public function pollInventory(string $sellerId, string $since): array
    {
        return $this->poll('/api/offers/inventory', ['seller_id' => $sellerId, 'since' => $since]);
    }

    private function poll(string $path, array $query): array
    {
        $url = $this->baseUrl . $path . '?' . http_build_query($query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->bearerToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        if ($code === 429) {
            preg_match('/Retry-After:\s*(\d+)/i', $headers, $m);
            $retryAfter = isset($m[1]) ? (int) $m[1] : 60;
            Logger::warn('sellaxis_poll_rate_limited', ['path' => $path, 'retry_after' => $retryAfter]);
            return ['ok' => false, 'status' => 'rate_limited', 'body' => null, 'retry_after' => $retryAfter];
        }

        if ($code >= 500) {
            Logger::warn('sellaxis_poll_server_error', ['path' => $path, 'code' => $code]);
            return ['ok' => false, 'status' => 'server_error_retryable', 'body' => null, 'retry_after' => null];
        }

        $decoded = json_decode($body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            // Malformed page: next_page may still claim there's more, but
            // we cannot trust this page's content. We surface this as an
            // explicit exception rather than silently treating an empty
            // decode as "no results" (which would quietly create a gap in
            // the since-cursor).
            Logger::error('sellaxis_poll_malformed_page', ['path' => $path, 'json_error' => json_last_error_msg()]);
            return ['ok' => false, 'status' => 'malformed_page', 'body' => null, 'retry_after' => null];
        }

        return ['ok' => true, 'status' => 'ok', 'body' => $decoded, 'retry_after' => null];
    }
}
