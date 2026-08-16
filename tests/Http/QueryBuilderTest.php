<?php

namespace Osos\Gaith\Sdk\Tests\Http;

use Osos\Gaith\Sdk\Http\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    public function testEmptyBuilderProducesEmptyString(): void
    {
        $this->assertSame('', (new QueryBuilder())->toString());
    }

    public function testSingleParam(): void
    {
        $qb = (new QueryBuilder())->add('external_user_id', 'user-1');

        $this->assertSame('external_user_id=user-1', $qb->toString());
    }

    public function testMultipleParamsJoinedWithAmpersand(): void
    {
        $qb = (new QueryBuilder())
            ->add('external_user_id', 'user-1')
            ->add('limit', 10);

        $this->assertSame('external_user_id=user-1&limit=10', $qb->toString());
    }

    public function testNullValuesAreSkipped(): void
    {
        $qb = (new QueryBuilder())
            ->add('external_user_id', 'user-1')
            ->add('before', null)
            ->add('limit', 10);

        $this->assertSame('external_user_id=user-1&limit=10', $qb->toString());
    }

    public function testBooleanIsEncodedAsTrueFalse(): void
    {
        $qb = (new QueryBuilder())->add('is_test', true);

        $this->assertSame('is_test=true', $qb->toString());
    }

    public function testValuesAreUrlEncoded(): void
    {
        $qb = (new QueryBuilder())->add('q', 'a b&c');

        $this->assertSame('q=' . rawurlencode('a b&c'), $qb->toString());
    }

    public function testFluentReturnsSameInstance(): void
    {
        $qb = new QueryBuilder();

        $this->assertSame($qb, $qb->add('a', '1'));
    }
}
