<?php

namespace Osos\Gaith\Sdk\Tests\Exceptions;

use Osos\Gaith\Sdk\Exceptions\GaithApiException;
use Osos\Gaith\Sdk\Exceptions\GaithAuthException;
use Osos\Gaith\Sdk\Exceptions\GaithForbiddenException;
use Osos\Gaith\Sdk\Exceptions\GaithGoneException;
use Osos\Gaith\Sdk\Exceptions\GaithNotFoundException;
use Osos\Gaith\Sdk\Exceptions\GaithRateLimitException;
use Osos\Gaith\Sdk\Exceptions\GaithValidationException;
use PHPUnit\Framework\TestCase;

final class ExceptionMappingTest extends TestCase
{
    public function testBaseExceptionExposesFields(): void
    {
        $e = new GaithApiException(500, 'upstream_error', '{"raw":true}', 'Server error');

        $this->assertSame(500, $e->statusCode());
        $this->assertSame('upstream_error', $e->serverCode());
        $this->assertSame('{"raw":true}', $e->responseBody());
        $this->assertSame('Server error', $e->getMessage());
    }

    public function testServerCodeCanBeNull(): void
    {
        $e = new GaithApiException(500, null, 'body', 'msg');

        $this->assertNull($e->serverCode());
    }

    /**
     * @dataProvider subclassProvider
     */
    public function testSubclassIsGaithApiException(string $class, int $expectedStatus): void
    {
        $e = new $class($expectedStatus, 'some_code', 'body', 'msg');

        $this->assertInstanceOf(GaithApiException::class, $e);
        $this->assertSame($expectedStatus, $e->statusCode());
    }

    public function subclassProvider(): array
    {
        return [
            [GaithAuthException::class, 401],
            [GaithForbiddenException::class, 403],
            [GaithNotFoundException::class, 404],
            [GaithGoneException::class, 410],
            [GaithValidationException::class, 422],
            [GaithRateLimitException::class, 429],
        ];
    }
}
