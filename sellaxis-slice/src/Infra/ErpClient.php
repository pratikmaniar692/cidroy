<?php

declare(strict_types=1);

namespace Forgeline\Infra;

/**
 * Talks to the fake ERP. Implements exactly the discipline the stub
 * documents: send Idempotency-Key on every POST; if the POST times out
 * (504), do NOT assume failure -- poll GET /erp/v2/purchase-orders/{key}
 * to find out what actually happened, because the stub is explicit that
 * the write can succeed even though the caller didn't hear back.
 */
final class ErpClient
{
    public function __construct(private string $baseUrl) {}

    /**
     * @return array{status: string, capture_ref: ?string, raw: ?array}
     *   status is one of: 'delivered', 'timed_out_but_confirmed',
     *   'timed_out_unconfirmed', 'failed'
     */
    public function postPurchaseOrder(string $idempotencyKey, array $payload): array
    {
        $result = $this->httpPost('/erp/v2/purchase-orders', $payload, $idempotencyKey, timeoutSeconds: 3);

        if ($result['timed_out']) {
            // Per the stub: the write may have succeeded even though we
            // didn't get a response. Never retry blindly here -- confirm
            // first.
            $confirmed = $this->confirm($idempotencyKey);
            if ($confirmed !== null) {
                return ['status' => 'timed_out_but_confirmed', 'capture_ref' => $confirmed['id'] ?? null, 'raw' => $confirmed];
            }
            return ['status' => 'timed_out_unconfirmed', 'capture_ref' => null, 'raw' => null];
        }

        if ($result['ok']) {
            return ['status' => 'delivered', 'capture_ref' => $result['body']['id'] ?? null, 'raw' => $result['body']];
        }

        return ['status' => 'failed', 'capture_ref' => null, 'raw' => $result['body'] ?? null];
    }

    public function confirm(string $idempotencyKey): ?array
    {
        $result = $this->httpGet("/erp/v2/purchase-orders/{$idempotencyKey}");
        if ($result['ok']) {
            return $result['body'];
        }
        return null;
    }

    private function httpPost(string $path, array $payload, string $idempotencyKey, int $timeoutSeconds): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Idempotency-Key: ' . $idempotencyKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $timedOut = $errno === CURLE_OPERATION_TIMEOUTED || $code === 504;

        return [
            'ok' => !$timedOut && $code >= 200 && $code < 300,
            'timed_out' => $timedOut,
            'code' => $code,
            'body' => $body ? json_decode($body, true) : null,
        ];
    }

    private function httpGet(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $body ? json_decode($body, true) : null];
    }
}
