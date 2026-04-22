<?php

namespace App\Services\Zq;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZqApiService
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.zq.base_url', '')) !== ''
            && trim((string) config('services.zq.api_key', '')) !== '';
    }

    public function createOrder(array $orders): array
    {
        return $this->request('post', '/order/create', $orders);
    }

    public function getOrderDetail(string $platformOrderId): array
    {
        return $this->request('get', '/order/detail/' . rawurlencode($platformOrderId));
    }

    public function getTracking(string $platformOrderId): array
    {
        return $this->request('get', '/order/tracking/' . rawurlencode($platformOrderId));
    }

    public function getInventory(string $sku): array
    {
        return $this->request('get', '/order/inventory/' . rawurlencode($sku));
    }

    public function getImportProductList(array $filters = []): array
    {
        return $this->request('post', '/import_product/list', $filters);
    }

    public function getImportProductDetail(int|string $id): array
    {
        return $this->request('get', '/import_product/' . rawurlencode((string) $id));
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $baseUrl = rtrim((string) config('services.zq.base_url', ''), '/');
        $apiKey = trim((string) config('services.zq.api_key', ''));
        $timeout = max(5, (int) config('services.zq.timeout', 30));

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('ZQ API configuration is incomplete.');
        }

        $url = $baseUrl . '/' . ltrim($path, '/');

        $client = Http::timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-API-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ]);

        $response = $method === 'get'
            ? $client->get($url)
            : $client->post($url, $payload ?? []);

        $json = $response->json();

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'ZQ request failed (%s): %s',
                $response->status(),
                is_array($json) ? json_encode($json) : $response->body()
            ));
        }

        return is_array($json) ? $json : ['raw' => $response->body()];
    }
}
