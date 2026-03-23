<?php

namespace CostPlus\NoPayN;

/**
 * HTTP client for the NoPayN/Cost+ Payment Gateway API.
 *
 * Base URL: https://api.nopayn.co.uk
 * Auth: HTTP Basic (API key as username, empty password)
 */
class NoPayNApi
{
    private string $apiKey;
    private string $baseUrl;
    private ?NoPayNLogger $logger;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.nopayn.co.uk', ?NoPayNLogger $logger = null)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger;
    }

    /**
     * Create an order (HPP flow).
     * POST /v1/orders/
     *
     * @param array $params Order parameters (amount, currency, merchant_order_id, return_url, webhook_url, transactions, etc.)
     * @return array API response
     */
    public function createOrder(array $params): array
    {
        return $this->request('POST', '/v1/orders/', $params);
    }

    /**
     * Get order status.
     * GET /v1/orders/{id}/
     *
     * @param string $orderId NoPayN order UUID
     * @return array API response with order details and status
     */
    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/v1/orders/' . urlencode($orderId) . '/');
    }

    /**
     * Create a refund for an order.
     * POST /v1/orders/{id}/refunds/
     *
     * @param string $orderId NoPayN order UUID
     * @param int $amountCents Refund amount in smallest currency unit (cents)
     * @param string $description Reason for refund
     * @return array API response with refund details
     */
    public function createRefund(string $orderId, int $amountCents, string $description = ''): array
    {
        $body = ['amount' => $amountCents];
        if ($description !== '') {
            $body['description'] = $description;
        }
        return $this->request('POST', '/v1/orders/' . urlencode($orderId) . '/refunds/', $body);
    }

    /**
     * Capture an authorized transaction.
     * POST /v1/orders/{orderId}/transactions/{transactionId}/captures/
     *
     * @param string $orderId NoPayN order UUID
     * @param string $transactionId NoPayN transaction UUID
     * @return array API response with capture details
     */
    public function captureTransaction(string $orderId, string $transactionId): array
    {
        return $this->request(
            'POST',
            '/v1/orders/' . urlencode($orderId) . '/transactions/' . urlencode($transactionId) . '/captures/',
            []
        );
    }

    /**
     * Void an authorized transaction (full or partial).
     * POST /v1/orders/{orderId}/transactions/{transactionId}/voids/amount/
     *
     * @param string $orderId NoPayN order UUID
     * @param string $transactionId NoPayN transaction UUID
     * @param int $amountInCents Void amount in smallest currency unit (cents)
     * @param string $description Reason for void
     * @return array API response with void details
     */
    public function voidTransaction(string $orderId, string $transactionId, int $amountInCents, string $description = ''): array
    {
        $body = ['amount' => $amountInCents];
        if ($description !== '') {
            $body['description'] = $description;
        }
        return $this->request(
            'POST',
            '/v1/orders/' . urlencode($orderId) . '/transactions/' . urlencode($transactionId) . '/voids/amount/',
            $body
        );
    }

    /**
     * Perform an HTTP request to the NoPayN API.
     *
     * @param string $method HTTP method (GET, POST)
     * @param string $endpoint API endpoint path
     * @param array|null $body Request body (JSON-encoded for POST)
     * @return array Decoded JSON response
     * @throws \RuntimeException on network or API errors
     */
    private function request(string $method, string $endpoint, ?array $body = null): array
    {
        $url = $this->baseUrl . $endpoint;

        if ($this->logger) {
            $this->logger->logApiRequest($method, $endpoint, $body);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERPWD => $this->apiKey . ':',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            if ($this->logger) {
                $this->logger->logApiError($method, $endpoint, $curlError);
            }
            throw new \RuntimeException('NoPayN API request failed: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && $response !== '') {
            if ($this->logger) {
                $this->logger->logApiError($method, $endpoint, 'Invalid JSON: ' . substr($response, 0, 500));
            }
            throw new \RuntimeException('NoPayN API returned invalid JSON: ' . substr($response, 0, 500));
        }

        if ($httpCode >= 400) {
            $errorMsg = $decoded['error']['value'] ?? $decoded['error']['message'] ?? 'Unknown error';
            if ($this->logger) {
                $this->logger->logApiError($method, $endpoint, 'HTTP ' . $httpCode . ': ' . $errorMsg);
            }
            throw new \RuntimeException('NoPayN API error (HTTP ' . $httpCode . '): ' . $errorMsg);
        }

        if ($this->logger) {
            $this->logger->logApiResponse($method, $endpoint, $httpCode, $decoded ?? []);
        }

        return $decoded ?? [];
    }
}
