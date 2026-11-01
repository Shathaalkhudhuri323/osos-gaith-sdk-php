<?php

namespace Osos\Gaith\Sdk\Tests\Config;

use Osos\Gaith\Sdk\Config\GaithUser;
use PHPUnit\Framework\TestCase;

final class GaithUserTest extends TestCase
{
    public function testForReturnsGivenId(): void
    {
        $this->assertSame('user-123', GaithUser::for('user-123')->id());
    }

    public function testAMissingIdGivesEachCallerItsOwnAnonymousId(): void
    {
        $a = GaithUser::for(null);
        $b = GaithUser::for('');

        $this->assertStringStartsWith('anon-', $a->id());
        $this->assertStringStartsWith('anon-', $b->id());
        $this->assertNotSame($a->id(), $b->id());
    }
}
