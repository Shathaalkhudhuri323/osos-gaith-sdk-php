<?php

namespace Osos\Gaith\Sdk\Config;

final class GaithUser
{
    /** The header GAITH reads the signed identity proof from. */
    public const PROOF_HEADER = 'X-Gaith-Identity-Proof';

    /** @var string */
    private $id;

    /** @var callable|null fn(string $chatbotId): string, one fresh proof per call */
    private $proof;

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
     * @return array<string, string> the proof header for one request to $chatbotId, or none
     */
    public function proofHeaders(string $chatbotId): array
    {
        if ($this->proof === null) {
            return [];
        }

        return [self::PROOF_HEADER => ($this->proof)($chatbotId)];
    }
}
