<?php

namespace Osos\Gaith\Sdk\Tests\Config;

use Osos\Gaith\Sdk\Config\GaithUser;
use PHPUnit\Framework\TestCase;

final class GaithUserTest extends TestCase
{
    public function testForReturnsGivenId(): void
    {
        $user = GaithUser::for('user-123');

        $this->assertSame('user-123', $user->id());
    }

    public function testForWithNullGeneratesAnonymousId(): void
    {
        $user = GaithUser::for(null);

        $this->assertNotSame('', $user->id());
        $this->assertStringStartsWith('anon-', $user->id());
    }

    public function testForWithEmptyStringGeneratesAnonymousId(): void
    {
        $user = GaithUser::for('');

        $this->assertStringStartsWith('anon-', $user->id());
    }

    public function testAnonymousGeneratesUniqueIds(): void
    {
        $a = GaithUser::anonymous();
        $b = GaithUser::anonymous();

        $this->assertStringStartsWith('anon-', $a->id());
        $this->assertNotSame($a->id(), $b->id());
    }
}
