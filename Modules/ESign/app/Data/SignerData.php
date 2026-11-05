<?php

namespace Modules\ESign\Data;

use Modules\ESign\Exceptions\ESignDomainException;

final class SignerData
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $email = null,
        public readonly ?string $mobile = null,
        public readonly ?string $identifier = null,
        public readonly array $metadata = [],
    ) {
        if (trim($this->name) === '') {
            throw new ESignDomainException('Signer name is required.', 422);
        }

        if ($this->email === null && $this->mobile === null) {
            throw new ESignDomainException('Signer email or mobile is required.', 422);
        }

        if ($this->email !== null && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ESignDomainException('Signer email is invalid.', 422);
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) ($data['name'] ?? '')),
            email: self::nullableString($data['email'] ?? null),
            mobile: self::nullableString($data['mobile'] ?? null),
            identifier: self::nullableString($data['identifier'] ?? null),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'identifier' => $this->identifier,
            'metadata' => $this->metadata,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
