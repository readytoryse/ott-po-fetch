<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class OrderWiseClient
{
    public function sinceDate(): string
    {
        return CarbonImmutable::now(config('app.timezone'))->toDateString();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchOutstandingPurchaseOrderLines(): array
    {
        $sinceDate = $this->sinceDate();
        $response = $this->sendExport($sinceDate, $this->token());

        if ($response->unauthorized()) {
            Cache::forget($this->tokenCacheKey());
            $response = $this->sendExport($sinceDate, $this->token());
        }

        $response->throw();

        $payload = $response->json();

        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        foreach (['data', 'rows', 'results'] as $key) {
            if (is_array($payload) && is_array($payload[$key] ?? null) && array_is_list($payload[$key])) {
                return $payload[$key];
            }
        }

        throw new UnexpectedValueException('OrderWise export response did not contain a list of rows.');
    }

    private function sendExport(string $sinceDate, string $token): Response
    {
        $baseUrl = rtrim((string) config('services.orderwise.base_url'), '/');
        $exportId = rawurlencode((string) config('services.orderwise.export_id'));

        return Http::acceptJson()
            ->timeout((int) config('services.orderwise.timeout_seconds'))
            ->withToken($token)
            ->post("{$baseUrl}/system/export-definition/{$exportId}", [
                ['name' => '@since', 'value' => $sinceDate],
            ]);
    }

    private function token(): string
    {
        return Cache::remember(
            $this->tokenCacheKey(),
            now()->addMinutes((int) config('services.orderwise.token_cache_minutes')),
            fn (): string => $this->requestToken(),
        );
    }

    private function requestToken(): string
    {
        $baseUrl = rtrim((string) config('services.orderwise.base_url'), '/');
        $response = Http::acceptJson()
            ->connectTimeout(10)
            ->timeout((int) config('services.orderwise.timeout_seconds'))
            ->withBasicAuth(
                (string) config('services.orderwise.username'),
                (string) config('services.orderwise.password'),
            )
            ->get("{$baseUrl}/token/gettoken");

        $response->throw();

        $payload = $response->json();
        $token = is_array($payload)
            ? ($payload['access_token'] ?? $payload['token'] ?? data_get($payload, 'data.access_token') ?? data_get($payload, 'data.token'))
            : $payload;
        $token = is_string($token) ? trim($token) : '';

        if ($token === '') {
            $token = trim($response->body(), " \t\n\r\0\x0B\"");
        }

        if ($token === '') {
            throw new UnexpectedValueException('OrderWise token endpoint returned an empty token.');
        }

        return preg_replace('/^Bearer\s+/i', '', $token) ?? $token;
    }

    private function tokenCacheKey(): string
    {
        $identity = (string) config('services.orderwise.base_url').'|'.(string) config('services.orderwise.username');

        return 'orderwise.token.'.hash('sha256', $identity);
    }
}
