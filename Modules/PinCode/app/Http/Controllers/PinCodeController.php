<?php

namespace Modules\PinCode\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\PinCode\Models\Pincode;


class PinCodeController extends Controller
{
    use ApiResponse;

    const CACHE_TTL = 2592000; // 30 days

    public function getLocation($pincode)
    {
        // 1. Validate
        if (!preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            return $this->error('Invalid Pincode. Must be a 6-digit Indian pincode.', null, 400, 'INVALID_PINCODE');
        }

        // 2. Cache check
        $cacheKey = "pincode_{$pincode}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $this->success($cached, 'Pincode found (cached)', 200, 'SUCCESS_CACHED');
        }

        // 3. Try database first (if seeded)
        $dbRecord = Pincode::find($pincode);
        if ($dbRecord) {
            $response = [
                'status'       => 'success',
                'pincode'      => $dbRecord->pincode,
                'state'        => $dbRecord->state,
                'districts'    => $dbRecord->districts,
                'cities'       => $dbRecord->cities,
                'post_offices' => $dbRecord->post_offices,
                'source'       => 'database',
            ];
            Cache::put($cacheKey, $response, self::CACHE_TTL);
            return $this->success($response, 'Pincode found (database)', 200, 'SUCCESS_DB');
        }

        // 4. Try India Post API with fallback
        $result = $this->fetchFromIndiaPost($pincode);
        if ($result) {
            Cache::put($cacheKey, $result, self::CACHE_TTL);
            $this->storeInDatabase($pincode, $result);
            return $this->success($result, 'Pincode found (API)', 200, 'SUCCESS_API');
        }

        // 5. Manual config as last resort
        $manual = config("manual_pincodes.{$pincode}");
        if ($manual && isset($manual['state'])) {
            $result = [
                'status'       => 'success',
                'pincode'      => $pincode,
                'state'        => $manual['state'],
                'districts'    => $manual['districts'] ?? [],
                'cities'       => $manual['cities'] ?? [],
                'post_offices' => $manual['post_offices'] ?? [],
                'source'       => 'manual',
            ];
            Cache::put($cacheKey, $result, self::CACHE_TTL);
            $this->storeInDatabase($pincode, $result);
            return $this->success($result, 'Pincode found (manual)', 200, 'SUCCESS_MANUAL');
        }

        // 6. Nothing works
        Log::warning("Pincode not found: {$pincode}");
        return $this->error('Pincode not found', null, 404, 'PINCODE_NOT_FOUND');
    }

    /**
     * Fetch from India Post API with detailed logging & Guzzle fallback
     */
   private function fetchFromIndiaPost(string $pincode)
{
    $url = "https://api.postalpincode.in/pincode/{$pincode}";

    Log::info("Fetching pincode {$pincode} from India Post API");

    try {

        $response = Http::withOptions([
                'verify' => false,
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_FORBID_REUSE => true,
                    CURLOPT_FRESH_CONNECT => true,
                ],
            ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'application/json',
                'Connection' => 'close',
            ])
            ->timeout(20)
            ->connectTimeout(10)
            ->retry(3, 500)
            ->get($url);

        Log::info("India Post API Status: " . $response->status());

        if (!$response->successful()) {
            Log::warning("India Post API HTTP error", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();

        Log::info("India Post API response received");

        return $this->normalizeIndiaPostResponse($data, $pincode);

    } catch (\Throwable $e) {

        Log::error("India Post API failed", [
            'message' => $e->getMessage(),
            'trace'   => $e->getTraceAsString(),
        ]);

        return null;
    }
}

    /**
     * Normalize India Post response – case‑insensitive & safe
     */
    private function normalizeIndiaPostResponse(array $data, string $pincode): ?array
    {
        if (!isset($data[0])) {
            Log::warning("India Post API response has no [0] index", ['data' => $data]);
            return null;
        }
        
        $first = $data[0];
        $status = $first['Status'] ?? $first['status'] ?? null;
        
        if ($status !== 'Success') {
            Log::warning("India Post API status not Success", ['status' => $status]);
            return null;
        }
        
        $offices = $first['PostOffice'] ?? [];
        if (empty($offices)) {
            Log::warning("India Post API returned no PostOffice");
            return null;
        }
        
        // Extract unique districts & cities
        $districts = collect($offices)->pluck('District')->filter()->unique()->values()->toArray();
        $cities    = collect($offices)->pluck('Name')->filter()->unique()->values()->toArray();
        $state     = $offices[0]['State'] ?? $offices[0]['state'] ?? null;
        
        if (!$state) {
            Log::warning("No state found in first post office", ['office' => $offices[0]]);
            return null;
        }
        
        return [
            'status'       => 'success',
            'pincode'      => $pincode,
            'state'        => $state,
            'districts'    => $districts,
            'cities'       => $cities,
            'post_offices' => $offices,
            'source'       => 'india_post',
        ];
    }

    /**
     * Save to database for future offline use
     */
    private function storeInDatabase(string $pincode, array $data): void
    {
        try {
            Pincode::updateOrCreate(
                ['pincode' => $pincode],
                [
                    'state'        => $data['state'],
                    'districts'    => $data['districts'],
                    'cities'       => $data['cities'],
                    'post_offices' => $data['post_offices'],
                    'last_fetched' => now(),
                ]
            );
        } catch (\Exception $e) {
            Log::error("Failed to store pincode {$pincode} in DB: " . $e->getMessage());
        }
    }
}