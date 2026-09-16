<?php

declare(strict_types=1);

final class IcaClient
{
    public function findStores(string $postcode): array
    {
        $postcode = preg_replace('/\D+/', '', $postcode) ?? '';
        if (strlen($postcode) !== 5) {
            throw new InvalidArgumentException('Ange ett svenskt postnummer med fem siffror.');
        }

        $payload = $this->requestJson(ICA_STORE_API . '?' . http_build_query([
            'zip' => $postcode,
            'customerType' => 'B2C',
        ]));

        $stores = [];
        foreach ($payload as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $candidate) {
                if (!is_array($candidate) || empty($candidate['id']) || empty($candidate['name'])) {
                    continue;
                }
                $stores[(string) $candidate['id']] = [
                    'ica_store_id' => (string) $candidate['id'],
                    'account_id' => (string) ($candidate['accountId'] ?? ''),
                    'name' => (string) $candidate['name'],
                    'store_format' => (string) ($candidate['storeFormat'] ?? 'other'),
                    'street' => (string) ($candidate['street'] ?? ''),
                    'postcode' => (string) ($candidate['zipCode'] ?? ''),
                    'city' => (string) ($candidate['city'] ?? ''),
                    'latitude' => isset($candidate['latitude']) && is_numeric($candidate['latitude']) ? (float) $candidate['latitude'] : null,
                    'longitude' => isset($candidate['longitude']) && is_numeric($candidate['longitude']) ? (float) $candidate['longitude'] : null,
                ];
            }
        }

        return array_slice(array_values($stores), 0, 30);
    }

    public function findPromotedProducts(string $accountId, array $terms): array
    {
        if (!preg_match('/^\d{4,20}$/', $accountId)) {
            throw new InvalidArgumentException('Butiken saknar ett giltigt ICA accountId.');
        }

        $products = [];
        foreach (array_slice($terms, 0, 12) as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $base = sprintf(ICA_PRODUCT_API_TEMPLATE, rawurlencode($accountId));
            $payload = $this->requestJson($base . '?' . http_build_query([
                'q' => $term,
                'tag' => 'web',
                'maxPageSize' => 60,
            ]), 'https://handlaprivatkund.ica.se/stores/' . rawurlencode($accountId) . '/products');

            $nodes = [];
            $this->collectProductNodes($payload, $nodes);
            foreach ($nodes as $node) {
                $normalized = $this->normalizeProduct($node, $accountId);
                if ($normalized === null) {
                    continue;
                }
                $products[$normalized['external_id']] = $normalized;
            }
        }
        return array_values($products);
    }

    private function requestJson(string $url, ?string $referer = null): array
    {
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: sv-SE,sv;q=0.9,en;q=0.7',
            'User-Agent: Mozilla/5.0 (compatible; LokalaFyndDemo/1.0)',
        ];
        if ($referer !== null) {
            $headers[] = 'Referer: ' . $referer;
        }

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => '',
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($body === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('ICA svarade inte som väntat (HTTP ' . ($status ?: 'nätverksfel') . '). ' . $error);
            }
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 20,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $context);
            $statusLine = $http_response_header[0] ?? '';
            preg_match('/\s(\d{3})\s/', $statusLine, $matches);
            $status = isset($matches[1]) ? (int) $matches[1] : 0;
            if ($body === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('ICA svarade inte som väntat (HTTP ' . ($status ?: 'nätverksfel') . ').');
            }
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('ICA returnerade ett oväntat dataformat.');
        }
        return $decoded;
    }

    private function collectProductNodes(mixed $node, array &$products): void
    {
        if (!is_array($node)) {
            return;
        }
        $hasName = isset($node['name']) || isset($node['productName']) || isset($node['displayName']);
        $hasProductData = isset($node['price']) || isset($node['promotions']) || isset($node['promotion'])
            || isset($node['retailerProductId']) || isset($node['productId']);
        if ($hasName && $hasProductData) {
            $products[] = $node;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectProductNodes($child, $products);
            }
        }
    }

    private function normalizeProduct(array $node, string $accountId): ?array
    {
        $name = $this->firstString($node, ['name', 'productName', 'displayName', 'title']);
        if ($name === '') {
            return null;
        }

        $promotionValue = $node['promotions'] ?? $node['promotion'] ?? $node['promotionText'] ?? null;
        $promotionText = $this->collectPromotionText($promotionValue);
        $promotionPrice = $this->firstNumber($node, ['promotionPrice', 'campaignPrice', 'dealPrice']);
        if ($promotionText === '' && $promotionPrice === null) {
            return null;
        }

        $externalId = $this->firstString($node, ['retailerProductId', 'sku', 'productId', 'id', 'gtin']);
        $brand = $this->valueAsString($node['brand'] ?? '');
        $quantity = $this->firstString($node, ['packSizeDescription', 'sizeOrQuantity', 'unit', 'quantity']);
        if ($externalId === '') {
            $externalId = 'generated-' . sha1($name . '|' . $brand . '|' . $quantity);
        }

        $price = $this->firstNumber($node, ['price', 'sellingPrice', 'currentPrice', 'salesPrice']);
        $regularPrice = $this->firstNumber($node, ['regularPrice', 'ordinaryPrice', 'originalPrice', 'comparisonPrice']);
        $dealPrice = $promotionPrice ?? $price;
        $imageUrl = $this->extractUrl($node['image'] ?? $node['imageUrl'] ?? $node['images'] ?? null);
        $productUrl = $this->extractUrl($node['url'] ?? $node['productUrl'] ?? null);

        return [
            'external_id' => $externalId,
            'product_name' => $name,
            'brand' => $brand,
            'description' => $this->firstString($node, ['description', 'shortDescription']),
            'deal_price' => $dealPrice,
            'regular_price' => $regularPrice,
            'price_text' => $dealPrice !== null ? money($dealPrice) : $promotionText,
            'quantity_text' => $quantity,
            'promotion_text' => $promotionText ?: 'Kampanjpris',
            'image_url' => $imageUrl ?: null,
            'product_url' => $productUrl ?: 'https://handlaprivatkund.ica.se/stores/' . rawurlencode($accountId) . '/products',
            'valid_from' => $this->dateValue($node, ['validFrom', 'startDate', 'promotionStart']),
            'valid_to' => $this->dateValue($node, ['validTo', 'endDate', 'promotionEnd']),
        ];
    }

    private function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $value = $this->valueAsString($data[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function valueAsString(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            foreach (['name', 'label', 'text', 'value', 'description'] as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    return trim((string) $value[$key]);
                }
            }
        }
        return '';
    }

    private function firstNumber(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            $value = $data[$key];
            if (is_array($value)) {
                $value = $value['value'] ?? $value['amount'] ?? $value['price'] ?? null;
            }
            if (is_numeric($value)) {
                return round((float) $value, 2);
            }
        }
        return null;
    }

    private function collectPromotionText(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (!is_array($value)) {
            return '';
        }
        $parts = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($value));
        foreach ($iterator as $key => $item) {
            if (is_scalar($item) && in_array((string) $key, ['name', 'label', 'text', 'description', 'promotionText', 'condition'], true)) {
                $text = trim((string) $item);
                if ($text !== '' && !is_numeric($text)) {
                    $parts[] = $text;
                }
            }
        }
        return implode(' · ', array_slice(array_unique($parts), 0, 3));
    }

    private function extractUrl(mixed $value): string
    {
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        if (is_array($value)) {
            foreach (['url', 'src', 'large', 'medium', 'original'] as $key) {
                if (isset($value[$key])) {
                    $url = $this->extractUrl($value[$key]);
                    if ($url !== '') {
                        return $url;
                    }
                }
            }
            foreach ($value as $child) {
                $url = $this->extractUrl($child);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        return '';
    }

    private function dateValue(array $data, array $keys): ?string
    {
        $value = $this->firstString($data, $keys);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}

function upsertIcaDeals(PDO $db, int $storeId, array $products): int
{
    $find = $db->prepare('SELECT id FROM deals WHERE store_id = :store_id AND external_id = :external_id');
    $insert = $db->prepare(
        'INSERT INTO deals (store_id, external_id, product_name, brand, description, deal_price, regular_price,
         price_text, quantity_text, promotion_text, image_url, product_url, valid_from, valid_to, source, synced_at)
         VALUES (:store_id, :external_id, :product_name, :brand, :description, :deal_price, :regular_price,
         :price_text, :quantity_text, :promotion_text, :image_url, :product_url, :valid_from, :valid_to, \'ica_api\', CURRENT_TIMESTAMP)'
    );
    $update = $db->prepare(
        'UPDATE deals SET product_name = :product_name, brand = :brand, description = :description,
         deal_price = :deal_price, regular_price = :regular_price, price_text = :price_text,
         quantity_text = :quantity_text, promotion_text = :promotion_text, image_url = :image_url,
         product_url = :product_url, valid_from = :valid_from, valid_to = :valid_to,
         source = \'ica_api\', synced_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    $db->beginTransaction();
    try {
        foreach ($products as $product) {
            $find->execute(['store_id' => $storeId, 'external_id' => $product['external_id']]);
            $id = $find->fetchColumn();
            if ($id) {
                $updateValues = $product;
                unset($updateValues['external_id']);
                $update->execute($updateValues + ['id' => (int) $id]);
            } else {
                $insert->execute($product + ['store_id' => $storeId]);
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return count($products);
}
