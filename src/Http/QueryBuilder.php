<?php

namespace Osos\Gaith\Sdk\Http;

final class QueryBuilder
{
    /** @var array<string, string> */
    private $params = [];

    /**
     * @param string|int|float|bool|null $value
     */
    public function add(string $name, $value): self
    {
        if ($value === null) {
            return $this;
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        $this->params[$name] = (string) $value;

        return $this;
    }

    public function toString(): string
    {
        if ($this->params === []) {
            return '';
        }

        $pairs = [];
        foreach ($this->params as $name => $value) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }
}
