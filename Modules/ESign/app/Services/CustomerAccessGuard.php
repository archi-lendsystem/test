<?php

namespace Modules\ESign\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Modules\ESign\Models\ESignDocument;

final class CustomerAccessGuard
{
    public function assert(Request $request, ESignDocument $document): void
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthorizationException('Authentication is required.');
        }

        $userKey = (string) config('esign.authorization.customer_user_key', 'auth_identifier');
        $lendingKey = (string) config('esign.authorization.customer_lending_key', 'customer_id');
        $customer = $document->customerLending()->firstOrFail();

        $userValue = $userKey === 'auth_identifier'
            ? $user->getAuthIdentifier()
            : data_get($user, $userKey);
        $lendingValue = data_get($customer, $lendingKey);

        if ($userValue === null || $lendingValue === null) {
            throw new AuthorizationException('Customer ownership could not be verified.');
        }

        if (! hash_equals((string) $lendingValue, (string) $userValue)) {
            throw new AuthorizationException('You cannot access this eSign agreement.');
        }
    }
}
