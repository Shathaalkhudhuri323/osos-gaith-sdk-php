<?php

namespace Osos\Gaith\Sdk\Config;

final class GaithUser
{
    /** @var string */
    private $id;

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
}
