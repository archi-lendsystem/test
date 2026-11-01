<?php

namespace Modules\PaydayLoanPartner\Services;

use Modules\PaydayLoanPartner\Models\PaydayLoanPartner;

class PaydayPartnerService
{
    /**
     * Generate a unique 10-digit Partner ID.
     * Ensures no duplicate exists in the database.
     */
    public function generateUniquePartnerId(): int
    {
        do {
            $partnerId = random_int(1000000000, 9999999999);
        } while (PaydayLoanPartner::where('payday_partner_id', $partnerId)->exists());

        return $partnerId;
    }

    /**
     * Generate a 12-character alphanumeric Client ID.
     */
    public function generateClientId(): string
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $clientId = '';

        for ($i = 0; $i < 12; $i++) {
            $clientId .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $clientId;
    }

    /**
     * Generate a secure 32-character Client Secret.
     */
    public function generateClientSecret(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * =====================================================
     * FUTURE METHODS (For later use)
     * =====================================================
     * 
     * These methods will be implemented when we add
     * external API integration and token management.
     */

    /**
     * Get a valid access token for the partner.
     * (Will handle token expiry + auto refresh in future)
     */
    public function getValidAccessToken(PaydayLoanPartner $partner): ?string
    {
        // TODO: Add token expiry check + refresh logic here
        return $partner->access_token;
    }

    /**
     * Refresh access token from partner's API.
     */
    public function refreshAccessToken(PaydayLoanPartner $partner): bool
    {
        // TODO: Implement OAuth token refresh logic here
        return false;
    }
}