<?php

namespace App\Services\Shipping;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class JntShippingService
{
    public function bookShipment(array $payload): array
    {
        return $this->request('post', (string) config('services.jnt.book_path', '/webopenplatformapi/api/order/addOrder'), $payload, [
            'endpoint' => 'create_order',
        ]);
    }

    public function trackShipment(string $trackingNo): array
    {
        return $this->request('post', (string) config('services.jnt.track_path', '/webopenplatformapi/api/logistics/trace/query'), [
            'billCode' => $trackingNo,
            'waybillNo' => $trackingNo,
            'trackingNo' => $trackingNo,
        ], [
            'endpoint' => 'track_query',
        ]);
    }

    private function request(string $method, string $path, ?array $payload = null, array $meta = []): array
    {
        $baseUrl = rtrim((string) config('services.jnt.base_url', ''), '/');
        if ($baseUrl === '') {
            $baseUrl = $this->defaultBaseUrl();
        }

        $customerCode = trim((string) config('services.jnt.customer_code', ''));
        $apiAccount = trim((string) config('services.jnt.api_account', ''));
        $password = trim((string) config('services.jnt.password', ''));
        $privateKey = trim((string) config('services.jnt.private_key', ''));
        if ($customerCode === '' || $apiAccount === '' || $password === '' || $privateKey === '') {
            throw new RuntimeException('J&T credentials are incomplete. Set customer code, api account, password, and private key.');
        }

        $timeout = max(5, (int) config('services.jnt.timeout', 20));
        $url = $this->resolveUrl($baseUrl, $path);
        $bizPayload = $this->normalizeBizPayload($payload ?? []);
        $bizContent = json_encode($bizPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bizContent === false) {
            throw new RuntimeException('Failed to encode J&T bizContent payload.');
        }

        $encryptedPassword = $this->encryptPassword($password);
        $businessDigest = $this->generateBusinessDigest($customerCode, $password, $privateKey);
        $headerDigestOverride = trim((string) config('services.jnt.header_digest_override', ''));
        $headerDigest = $headerDigestOverride !== ''
            ? $headerDigestOverride
            : $this->generateHeaderDigest($bizContent, $privateKey);
        $timestamp = (string) now()->valueOf();

        $formPayload = [
            'customerCode' => $customerCode,
            'pwd' => $encryptedPassword,
            'digest' => $businessDigest,
            'apiAccount' => $apiAccount,
            'bizContent' => $bizContent,
            'dataDigest' => $headerDigest,
        ];

        $client = Http::timeout($timeout)
            ->acceptJson()
            ->asForm()
            ->withHeaders([
                'apiAccount' => $apiAccount,
                'digest' => $headerDigest,
                'timestamp' => $timestamp,
            ]);

        $response = $method === 'get'
            ? $client->get($url, $formPayload)
            : $client->post($url, $formPayload);

        return $this->decodeResponse($response, $url, [
            'request' => [
                'endpoint' => $meta['endpoint'] ?? null,
                'url' => $url,
                'headers' => [
                    'apiAccount' => $apiAccount,
                    'digest' => $headerDigest,
                    'timestamp' => $timestamp,
                ],
                'form' => $formPayload,
                'biz_payload' => $bizPayload,
                'header_digest_override_used' => $headerDigestOverride !== '',
                'header_digest_override_value' => $headerDigestOverride !== '' ? $headerDigestOverride : null,
                'header_digest_candidates' => $this->debugHeaderDigestCandidates($bizContent, $privateKey),
            ],
        ]);
    }

    private function decodeResponse(Response $response, string $url, array $context = []): array
    {
        $json = $response->json();
        if ($response->successful()) {
            $payload = is_array($json) ? $json : ['raw' => $response->body()];
            return array_merge($payload, [
                '_debug' => $context['request'] ?? null,
            ]);
        }

        throw new RuntimeException(
            sprintf(
                'J&T request failed (%s) at %s: %s',
                $response->status(),
                $url,
                is_array($json) ? json_encode($json) : $response->body()
            )
        );
    }

    private function defaultBaseUrl(): string
    {
        return (bool) config('services.jnt.is_sandbox', true)
            ? 'https://demoopenapi.jtcargo.com.ph'
            : 'https://openapi.jtcargo.com.ph';
    }

    private function resolveUrl(string $baseUrl, string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    private function encryptPassword(string $password): string
    {
        $suffix = (string) config('services.jnt.password_suffix', 'jadata236t2');
        return md5($password . $suffix);
    }

    private function generateBusinessDigest(string $customerCode, string $password, string $privateKey): string
    {
        return base64_encode(md5($customerCode . $password . $privateKey, true));
    }

    private function generateHeaderDigest(string $bizContent, string $privateKey): string
    {
        return base64_encode(md5($privateKey . $bizContent, true));
    }

    private function debugHeaderDigestCandidates(string $bizContent, string $privateKey): array
    {
        $urlEncodedBizContent = urlencode($bizContent);

        return [
            'current_privateKey_plus_bizContent_raw_md5' => base64_encode(md5($privateKey . $bizContent, true)),
            'bizContent_plus_privateKey_raw_md5' => base64_encode(md5($bizContent . $privateKey, true)),
            'current_privateKey_plus_bizContent_hex_md5' => base64_encode(md5($privateKey . $bizContent, false)),
            'bizContent_plus_privateKey_hex_md5' => base64_encode(md5($bizContent . $privateKey, false)),
            'privateKey_plus_urlencoded_bizContent_raw_md5' => base64_encode(md5($privateKey . $urlEncodedBizContent, true)),
            'urlencoded_bizContent_plus_privateKey_raw_md5' => base64_encode(md5($urlEncodedBizContent . $privateKey, true)),
            'privateKey_plus_urlencoded_bizContent_hex_md5' => base64_encode(md5($privateKey . $urlEncodedBizContent, false)),
            'urlencoded_bizContent_plus_privateKey_hex_md5' => base64_encode(md5($urlEncodedBizContent . $privateKey, false)),
        ];
    }

    private function normalizeBizPayload(array $payload): array
    {
        if (Arr::has($payload, ['txlogisticId']) || Arr::has($payload, ['billCode']) || Arr::has($payload, ['waybillNo'])) {
            $customerCode = trim((string) config('services.jnt.customer_code', ''));
            $password = trim((string) config('services.jnt.password', ''));
            $privateKey = trim((string) config('services.jnt.private_key', ''));

            if ($customerCode !== '' && $password !== '' && $privateKey !== '' && !Arr::has($payload, ['customerCode', 'digest'])) {
                $payload['customerCode'] = $customerCode;
                $payload['digest'] = $this->generateBusinessDigest($customerCode, $password, $privateKey);
            }

            return $payload;
        }

        $firstItem = Arr::first((array) ($payload['items'] ?? [])) ?: [];
        $quantity = max(1, (int) (is_array($firstItem) ? ($firstItem['quantity'] ?? 1) : 1));
        $declaredValue = (float) ($payload['declared_value'] ?? 0);
        $now = now();
        $startTime = $now->copy()->addMinutes(15)->format('Y-m-d H:i:s');
        $endTime = $now->copy()->addDay()->format('Y-m-d H:i:s');
        $customerCode = trim((string) config('services.jnt.customer_code', ''));
        $password = trim((string) config('services.jnt.password', ''));
        $privateKey = trim((string) config('services.jnt.private_key', ''));
        $businessDigest = ($customerCode !== '' && $password !== '' && $privateKey !== '')
            ? $this->generateBusinessDigest($customerCode, $password, $privateKey)
            : null;

        return array_filter([
            'customerCode' => $customerCode !== '' ? $customerCode : null,
            'digest' => $businessDigest,
            'network' => config('services.jnt.network', ''),
            'serviceType' => (string) config('services.jnt.service_type', '02'),
            'countryCode' => (string) config('services.jnt.country_code', 'PHL'),
            'orderType' => (string) config('services.jnt.order_type', '1'),
            'receiver' => [
                'address' => $payload['recipient_address'] ?? null,
                'city' => $payload['recipient_city'] ?? config('services.jnt.sender_city'),
                'mobile' => $payload['recipient_phone'] ?? null,
                'mailBox' => $payload['recipient_email'] ?? null,
                'phone' => $payload['recipient_phone'] ?? null,
                'countryCode' => (string) config('services.jnt.country_code', 'PHL'),
                'name' => $payload['recipient_name'] ?? null,
                'company' => $payload['recipient_company'] ?? null,
                'postCode' => $payload['recipient_post_code'] ?? null,
                'prov' => $payload['recipient_province'] ?? config('services.jnt.sender_province'),
            ],
            'expressType' => (string) config('services.jnt.express_type', 'standard'),
            'deliveryType' => (string) config('services.jnt.delivery_type', '03'),
            'length' => (float) ($payload['length'] ?? config('services.jnt.default_length', 10)),
            'sendStartTime' => $payload['send_start_time'] ?? $startTime,
            'weight' => (float) ($payload['weight'] ?? config('services.jnt.default_weight', 1)),
            'remark' => $payload['remark'] ?? ($payload['payment_method'] ?? null),
            'txlogisticId' => $payload['reference_no'] ?? null,
            'goodsType' => (string) config('services.jnt.goods_type', 'bm000001'),
            'volume' => (float) ($payload['volume'] ?? config('services.jnt.default_volume', 1000)),
            'priceCurrency' => (string) config('services.jnt.price_currency', 'PHP'),
            'totalQuantity' => $quantity,
            'sender' => [
                'address' => config('services.jnt.sender_address'),
                'city' => config('services.jnt.sender_city'),
                'mobile' => config('services.jnt.sender_mobile'),
                'mailBox' => config('services.jnt.sender_email'),
                'phone' => config('services.jnt.sender_phone'),
                'countryCode' => (string) config('services.jnt.country_code', 'PHL'),
                'name' => config('services.jnt.sender_name', 'AF Home Warehouse'),
                'company' => config('services.jnt.sender_company', 'AF Home'),
                'postCode' => config('services.jnt.sender_post_code'),
                'prov' => config('services.jnt.sender_province'),
            ],
            'width' => (float) ($payload['width'] ?? config('services.jnt.default_width', 10)),
            'offerFee' => (float) ($payload['offer_fee'] ?? config('services.jnt.offer_fee', 0)),
            'items' => array_values(array_filter(array_map(function ($item) use ($declaredValue) {
                if (!is_array($item)) {
                    return null;
                }

                return [
                    'englishName' => $item['english_name'] ?? $item['name'] ?? 'Item',
                    'number' => max(1, (int) ($item['quantity'] ?? 1)),
                    'itemType' => $item['item_type'] ?? config('services.jnt.goods_type', 'bm000001'),
                    'itemName' => $item['item_name'] ?? $item['name'] ?? 'Item',
                    'priceCurrency' => $item['price_currency'] ?? config('services.jnt.price_currency', 'PHP'),
                    'itemValue' => (string) ($item['item_value'] ?? $declaredValue),
                    'chineseName' => $item['chinese_name'] ?? ($item['name'] ?? 'Item'),
                    'itemUrl' => $item['item_url'] ?? null,
                    'desc' => $item['desc'] ?? ($item['name'] ?? 'Item'),
                ];
            }, (array) ($payload['items'] ?? [])))),
            'sendEndTime' => $payload['send_end_time'] ?? $endTime,
            'height' => (float) ($payload['height'] ?? config('services.jnt.default_height', 10)),
            'operateType' => (int) ($payload['operate_type'] ?? config('services.jnt.operate_type', 1)),
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
