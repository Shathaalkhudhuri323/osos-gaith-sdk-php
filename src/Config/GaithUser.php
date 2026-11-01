<?php

namespace Osos\Gaith\Sdk\Config;

/**
 * The user a request to GAITH is made for: their GAITH user id, plus the extra headers
 * that go with each request for them (the identity proof and the trace id).
 */
final class GaithUser
{
    // The header that carries the signed identity proof to GAITH.
    public const PROOF_HEADER = 'X-Gaith-Identity-Proof';

    // The header GAITH already reads to tie its logs to ours (see GAITH's app/main.py).
    public const TRACE_HEADER = 'X-Trace-ID';

    /** @var string */
    private $id;

    /**
     * A function that makes a new proof when called. We keep a function instead of one
     * proof because every request needs a fresh one: GAITH refuses a proof it has seen before.
     *
     * @var callable|null fn(string $chatbotId): string
     */
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
     * Attach an identity proof to every request made for this user. The function is
     * called once per request, including when a dropped reply is picked up again,
     * so each request gets its own proof.
     *
     * @param callable $mint fn(string $chatbotId): string, given the chatbot the request goes to
     */
    public function withProof(callable $mint): self
    {
        $copy = clone $this;
        $copy->proof = $mint;

        return $copy;
    }

    /**
     * Attach a trace id to every request made for this user. It lets you follow one chat
     * message through the product's logs, GAITH's logs and the MCP's logs. Unlike the
     * proof, it stays the same for the whole message, even if the reply is picked up again.
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
     * The extra headers for one request to the given chatbot: a new proof and the trace id,
     * each only if it was set.
     *
     * @return array<string, string>
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
