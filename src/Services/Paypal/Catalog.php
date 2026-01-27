<?php

namespace Lyre\Billing\Services\Paypal;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Catalog
{
    // public static function createProduct(string $name, string $description, CatalogType $type, string $category, string $image, string $home = '')
    public static function createProduct(string $name, string $description, string $type, string $category, string $image, string $home = '')
    {
        // $type = $type->value;
        $token = Client::getOauthToken();
        $requestId = uniqid();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'PayPal-Request-Id' => $requestId,
                'Prefer' => 'return=representation',
            ])
            ->post(config('services.paypal.base_uri') . config('services.paypal.catalog_uri'), [
                'name' => $name,
                'description' => $description,
                'type' => $type,
                'category' => $category,
                'image_url' => $image,
                'home_url' => $home ?? config('app.url'),
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Create Catalog Product Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to create catalog product.');
    }

    public static function listProducts(int $pageSize = 10, int $page = 1, bool $totalRequired = false): array
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->get(config('services.paypal.base_uri') . config('services.paypal.catalog_uri'), [
                'page_size' => $pageSize,
                'page' => $page,
                'total_required' => $totalRequired ? 'true' : 'false',
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal List Products Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to list catalog products.');
    }

    public static function getProductDetails(string $productId): array
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Prefer' => 'return=representation',
            ])
            ->get(config('services.paypal.base_uri') . config('services.paypal.catalog_uri') . "/{$productId}");

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Get Product Details Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to retrieve catalog product details.');
    }

    public static function updateProduct(string $productId, array $operations)
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->patch(config('services.paypal.base_uri') . config('services.paypal.catalog_uri') . "/{$productId}", $operations);

        if ($response->noContent()) {
            return true;
        }

        Log::error('PayPal Update Catalog Product Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to update catalog product.');
    }

    public static function updateProductName(string $productId, string $name): bool
    {
        return self::updateProduct($productId, [[
            'op' => 'replace',
            'path' => '/name',
            'value' => $name,
        ]]);
    }

    public static function updateProductDescription(string $productId, string $description): bool
    {
        return self::updateProduct($productId, [[
            'op' => 'replace',
            'path' => '/description',
            'value' => $description,
        ]]);
    }

    public static function updateProductType(string $productId, string $type): bool
    {
        return self::updateProduct($productId, [[
            'op' => 'replace',
            'path' => '/type',
            'value' => $type,
        ]]);
    }

    public static function updateProductCategory(string $productId, string $category): bool
    {
        return self::updateProduct($productId, [[
            'op' => 'replace',
            'path' => '/category',
            'value' => $category,
        ]]);
    }

    public static function updateProductImage(string $productId, string $imageUrl): bool
    {
        return self::updateProduct($productId, [[
            'op' => 'replace',
            'path' => '/image_url',
            'value' => $imageUrl,
        ]]);
    }

    public static function updateProductHomeUrl(string $productId, string $homeUrl): bool
    {
        return self::updateProduct($productId, [[
            'op' => 'replace',
            'path' => '/home_url',
            'value' => $homeUrl,
        ]]);
    }
}
