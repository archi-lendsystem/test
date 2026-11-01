<?php

namespace Modules\ESign\Tests\Unit;

use Modules\ESign\Data\SignerData;
use Modules\ESign\Exceptions\ESignDomainException;
use PHPUnit\Framework\TestCase;

final class SignerDataTest extends TestCase
{
    public function test_it_builds_a_valid_signer(): void
    {
        $signer = SignerData::fromArray([
            'name' => 'Borrower Name',
            'email' => 'borrower@example.com',
        ]);

        self::assertSame('Borrower Name', $signer->name);
        self::assertSame('borrower@example.com', $signer->email);
    }

    public function test_it_requires_a_contact_method(): void
    {
        $this->expectException(ESignDomainException::class);

        SignerData::fromArray(['name' => 'Borrower Name']);
    }
}
