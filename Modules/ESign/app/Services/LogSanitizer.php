<?php

namespace Modules\ESign\Services;

final class LogSanitizer
{
    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = $this->isSensitiveKey((string) $key)
                    ? '[REDACTED]'
                    : $this->sanitize($item);
            }

            return $result;
        }

        if (is_string($value) && mb_strlen($value) > 10000) {
            return mb_substr($value, 0, 10000).'…[TRUNCATED]';
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));

        foreach ((array) config('esign.redact_keys', []) as $sensitiveKey) {
            $sensitive = strtolower(str_replace(['-', '_'], '', (string) $sensitiveKey));

            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
