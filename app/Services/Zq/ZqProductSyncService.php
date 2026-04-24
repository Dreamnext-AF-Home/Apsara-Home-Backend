<?php

namespace App\Services\Zq;

use App\Models\ProductBrand;
use App\Models\SystemSetting;
use App\Models\ZqProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ZqProductSyncService
{
    public function __construct(
        private readonly ZqApiService $zqApiService
    ) {
    }

    public function syncImportProducts(
        array $filters = [],
        bool $resumeFromSaved = true,
        bool $resetCursor = false
    ): array
    {
        $zqSupplierBrandId = $this->resolveZqSupplierBrandId();
        $resolvedCursor = $this->resolveCursor($filters, $resumeFromSaved, $resetCursor);
        $requestFilters = $filters;

        if ($resolvedCursor !== null) {
            $requestFilters['cursor'] = $resolvedCursor;
        } else {
            unset($requestFilters['cursor']);
        }

        $response = $this->zqApiService->getImportProductList($requestFilters);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $records = is_array($data['records'] ?? null) ? $data['records'] : [];
        $externalIds = collect($records)
            ->map(function ($record) {
                $row = is_array($record) ? $record : [];
                $externalId = $row['id'] ?? null;

                if ($externalId === null || $externalId === '') {
                    return null;
                }

                return (string) $externalId;
            })
            ->filter()
            ->values()
            ->all();
        $existingExternalIds = $externalIds === []
            ? []
            : ZqProduct::query()
                ->whereIn('zqp_external_id', $externalIds)
                ->pluck('zqp_external_id')
                ->map(fn ($value) => (string) $value)
                ->all();
        $existingExternalIdLookup = array_fill_keys($existingExternalIds, true);

        $synced = 0;
        $skipped = 0;
        $failed = 0;
        $syncedIds = [];

        foreach ($records as $record) {
            $row = is_array($record) ? $record : [];
            $externalId = $row['id'] ?? null;

            if ($externalId === null || $externalId === '') {
                $failed++;
                continue;
            }

            if (isset($existingExternalIdLookup[(string) $externalId])) {
                $skipped++;
                continue;
            }

            try {
                $detailResponse = $this->zqApiService->getImportProductDetail((string) $externalId);
                $detail = is_array($detailResponse['data'] ?? null) ? $detailResponse['data'] : [];
                $payload = $this->mapDetailToColumns($detail, $row, $zqSupplierBrandId);

                $product = ZqProduct::query()->updateOrCreate(
                    ['zqp_external_id' => (string) $externalId],
                    $payload,
                );

                $synced++;
                $syncedIds[] = $product->zqp_id;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('ZQ product sync failed', [
                    'external_id' => $externalId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $hasMore = (bool) ($data['hasMore'] ?? false);
        $nextCursor = isset($data['nextCursor']) ? (string) $data['nextCursor'] : null;
        $this->persistCursorState($hasMore ? $nextCursor : null);

        return [
            'response' => $response,
            'summary' => [
                'requested' => count($records),
                'synced' => $synced,
                'skipped' => $skipped,
                'failed' => $failed,
            ],
            'synced_ids' => $syncedIds,
            'hasMore' => $hasMore,
            'nextCursor' => $nextCursor,
            'usedCursor' => $resolvedCursor,
            'savedCursor' => $hasMore ? $nextCursor : null,
        ];
    }

    public function resolveCursor(
        array $filters = [],
        bool $resumeFromSaved = true,
        bool $resetCursor = false
    ): ?string
    {
        if ($resetCursor) {
            return null;
        }

        if (array_key_exists('cursor', $filters) && $filters['cursor'] !== null && $filters['cursor'] !== '') {
            return (string) $filters['cursor'];
        }

        if (! $resumeFromSaved) {
            return null;
        }

        return $this->getSavedCursor();
    }

    public function getSavedCursor(): ?string
    {
        $settings = SystemSetting::query()->first();
        $cursor = $settings?->zq_saved_cursor;

        return is_string($cursor) && trim($cursor) !== '' ? trim($cursor) : null;
    }

    /**
     * Public wrapper so the controller can reuse the mapping when importing a single product.
     *
     * @param array<string, mixed> $detail
     * @param array<string, mixed> $summary
     * @param int|null $brandType
     * @return array<string, mixed>
     */
    public function mapDetailToColumnsPublic(array $detail, array $summary = [], ?int $brandType = null): array
    {
        return $this->mapDetailToColumns($detail, $summary, $brandType);
    }

    /**
     * @param array<string, mixed> $detail
     * @param array<string, mixed> $summary
     * @param int|null $brandType
     * @return array<string, mixed>
     */
    private function mapDetailToColumns(array $detail, array $summary = [], ?int $brandType = null): array
    {
        $images = $this->extractImages($detail['images'] ?? $summary['images'] ?? []);
        $specs = is_array($detail['specs'] ?? null) ? $detail['specs'] : [];

        $salePrices = [];
        $costPrices = [];
        $totalStock = 0;

        foreach ($specs as $spec) {
            $row = is_array($spec) ? $spec : [];

            if (isset($row['salesPrice']) && is_numeric($row['salesPrice'])) {
                $salePrices[] = (int) $row['salesPrice'];
            }

            if (isset($row['cost']) && is_numeric($row['cost'])) {
                $costPrices[] = (int) $row['cost'];
            }

            if (isset($row['amountOnSale']) && is_numeric($row['amountOnSale'])) {
                $totalStock += (int) $row['amountOnSale'];
            }
        }

        return [
            'zqp_offer_id' => $this->stringOrNull($detail['offerId'] ?? null),
            'zqp_brand_type' => $brandType,
            'zqp_category_id' => $this->stringOrNull($detail['categoryId'] ?? null),
            'zqp_category_name' => $this->stringOrNull($detail['categoryName'] ?? null),
            'zqp_subject' => $this->stringOrNull($detail['subject'] ?? $summary['subject'] ?? '') ?? '',
            'zqp_subject_cn' => $this->stringOrNull($detail['subjectCn'] ?? null),
            'zqp_description' => $this->stringOrNull($detail['description'] ?? null),
            'zqp_images' => $images,
            'zqp_primary_image' => $images[0] ?? null,
            'zqp_specs' => $specs,
            'zqp_source_type' => $this->enumValueOrString($detail['sourceType'] ?? $summary['sourceType'] ?? null),
            'zqp_status' => $this->enumValueOrString($detail['status'] ?? $summary['status'] ?? null),
            'zqp_import_status' => $this->enumValueOrString(
                $detail['importProductStatus']
                    ?? $detail['importproStatus']
                    ?? $summary['importProductStatus']
                    ?? $summary['importproStatus']
                    ?? null
            ),
            'zqp_product_url' => $this->stringOrNull($detail['productUrl'] ?? $summary['productUrl'] ?? null),
            'zqp_target_currency' => $this->stringOrNull($detail['targetCurrency'] ?? null),
            'zqp_shipping_to' => $this->stringOrNull($detail['shippingTo'] ?? null),
            'zqp_published_at' => $this->dateOrNull($detail['published'] ?? $summary['published'] ?? null),
            'zqp_source_created_at' => $this->dateOrNull($detail['createdAt'] ?? $summary['createdAt'] ?? null),
            'zqp_source_updated_at' => $this->dateOrNull($detail['updatedAt'] ?? null),
            'zqp_price_min_cents' => $salePrices === [] ? null : min($salePrices),
            'zqp_price_max_cents' => $salePrices === [] ? null : max($salePrices),
            'zqp_cost_min_cents' => $costPrices === [] ? null : min($costPrices),
            'zqp_cost_max_cents' => $costPrices === [] ? null : max($costPrices),
            'zqp_total_stock' => $totalStock,
            'zqp_variant_count' => count($specs),
            'zqp_raw_payload' => [
                'summary' => $summary,
                'detail' => $detail,
            ],
        ];
    }

    private function resolveZqSupplierBrandId(): ?int
    {
        $brand = ProductBrand::query()
            ->select(['pb_id'])
            ->whereRaw('LOWER(pb_name) = ?', ['zq supplier'])
            ->first();

        if ($brand) {
            return (int) $brand->pb_id;
        }

        $brand = ProductBrand::query()
            ->select(['pb_id'])
            ->where('pb_name', 'ilike', '%zq supplier%')
            ->first();

        return $brand ? (int) $brand->pb_id : null;
    }

    private function persistCursorState(?string $cursor): void
    {
        $settings = SystemSetting::query()->first();

        if (! $settings) {
            $settings = new SystemSetting();
        }

        $settings->zq_saved_cursor = $this->stringOrNull($cursor);
        $settings->zq_last_synced_at = now();
        $settings->save();
    }

    /**
     * @param mixed $images
     * @return array<int, string>
     */
    private function extractImages(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->map(function ($image) {
                if (is_array($image) && isset($image['image']) && is_string($image['image'])) {
                    return trim($image['image']);
                }

                if (is_string($image)) {
                    return trim($image);
                }

                return null;
            })
            ->filter(fn ($image) => is_string($image) && $image !== '')
            ->values()
            ->all();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function enumValueOrString(mixed $value): ?string
    {
        if (is_array($value)) {
            $preferred = $value['value'] ?? $value['label'] ?? null;
            return $this->stringOrNull($preferred);
        }

        return $this->stringOrNull($value);
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        $normalized = $this->stringOrNull($value);
        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized);
        } catch (\Throwable) {
            return null;
        }
    }
}
