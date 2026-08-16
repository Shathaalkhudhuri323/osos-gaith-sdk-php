<?php

namespace Osos\Gaith\Sdk\Exceptions;

class GaithApiException extends \RuntimeException
{
    /** @var int */
    private $statusCode;

    /** @var string|null */
    private $serverCode;

    /** @var string */
    private $responseBody;

    public function __construct(int $statusCode, ?string $serverCode, string $responseBody, string $message)
    {
        parent::__construct($message);

        $this->statusCode = $statusCode;
        $this->serverCode = $serverCode;
        $this->responseBody = $responseBody;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function serverCode(): ?string
    {
        return $this->serverCode;
    }

    public function responseBody(): string
    {
        return $this->responseBody;
    }
}
