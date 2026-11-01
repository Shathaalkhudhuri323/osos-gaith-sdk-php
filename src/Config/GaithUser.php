<?php

namespace Osos\Gaith\Sdk\Config;

final class GaithUser
{
    /** The header GAITH reads the signed identity proof from. */
    public const PROOF_HEADER = 'X-Gaith-Identity-Proof';

    /** The header GAITH takes a caller's trace id from (app/main.py). */
    public const TRACE_HEADER = 'X-Trace-ID';

    /** @var string */
    private $id;

    /** @var callable|null fn(string $chatbotId): string, one fresh proof per call */
    private $proof;

    /** @var string|null */
    private $traceId;

    private function __construct(string $id)
    {
        $this->id = $id;
    }

    public static function for(?string $id): self
    {
        if ($id === null || $id === '') {
            return self::anonymous();
        }

        return new self($id);
    }

    public static function anonymous(): self
    {
        return new self('anon-' . bin2hex(random_bytes(8)));
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Sends a signed identity proof with every request made for this user. The minter
     * is called once per HTTP request - a resumed stream included - because GAITH
     * refuses a proof whose `jti` it has already seen.
     *
     * @param callable $mint fn(string $chatbotId): string, the chatbot the request goes to
     */
    public function withProof(callable $mint): self
    {
        $copy = clone $this;
        $copy->proof = $mint;

        return $copy;
    }

    /**
     * Sends this trace id with every request made for this user, so one chat turn can
     * be followed through the host's, GAITH's and the MCP's logs. GAITH reads it as
     * X-Trace-ID. Unlike the proof it stays the same across a resumed stream.
     */
    public function withTraceId(string $traceId): self
    {
        $copy = clone $this;
        $copy->traceId = $traceId;

        return $copy;
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    /**
     * @return array<string, string> headers for one request to $chatbotId: a fresh proof
     *                               and the trace id, each only when set
     */
    public function requestHeaders(string $chatbotId): array
    {
        $headers = [];
        if ($this->proof !== null) {
            $headers[self::PROOF_HEADER] = ($this->proof)($chatbotId);
        }
        if ($this->traceId !== null) {
            $headers[self::TRACE_HEADER] = $this->traceId;
        }

        return $headers;
    }
}
