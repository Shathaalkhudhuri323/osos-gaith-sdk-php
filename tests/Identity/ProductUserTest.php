<?php

namespace Osos\Gaith\Sdk\Tests\Identity;

use Osos\Gaith\Sdk\Identity\ProductUser;
use PHPUnit\Framework\TestCase;

final class ProductUserTest extends TestCase
{
    public function testItJoinsProductCustomerAndPersonIntoTheGaithUserId(): void
    {
        $user = ProductUser::create('qhse', '7', '42');

        $this->assertSame('qhse', $user->product());
        $this->assertSame('7', $user->customer());
        $this->assertSame('42', $user->person());
        $this->assertSame('qhse:7:42', $user->externalUserId());
    }

    public function testIntegerIdsFromTheDatabaseAreAccepted(): void
    {
        $this->assertSame('erp:acme_db:42', ProductUser::create('erp', 'acme_db', 42)->externalUserId());
    }

    /**
     * @dataProvider unusableValues
     * @param mixed $value
     */
    public function testAnUnusableCustomerOrPersonGivesNoUser($value): void
    {
        $this->assertNull(ProductUser::create('qhse', $value, '42'));
        $this->assertNull(ProductUser::create('qhse', '7', $value));
    }

    public static function unusableValues(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'blank' => ['   '],
            // ':' separates the parts, so it would let one id pass for another.
            'separator' => ['7:42'],
            'space inside' => ['7 42'],
            'boolean' => [true],
            'float' => [7.5],
            'array' => [['7']],
            'too long' => [str_repeat('a', 101)],
        ];
    }

    public function testSurroundingSpacesAreTrimmed(): void
    {
        $this->assertSame('qhse:7:42', ProductUser::create('qhse', ' 7 ', "42\n")->externalUserId());
    }

    /**
     * @dataProvider badProducts
     */
    public function testAnInvalidProductIsAConfigurationError(string $product): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductUser::create($product, '7', '42');
    }

    public static function badProducts(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['QHSE'],
            'separator' => ['qhse:prod'],
            // GAITH reserves member:<id> for its own signed-in users.
            'reserved by GAITH' => ['member'],
        ];
    }
}
