<?php

namespace Osos\Gaith\Sdk\Identity;

/**
 * Who is chatting, as the host product knows them: which product, which customer
 * of that product, and which person at that customer.
 *
 * All three come from the host's server-side session. Together they are the GAITH
 * user id (`qhse:7:42`), which partitions chat history and, later, the user's MCP
 * connections - so two customers, or two products, can never share one.
 */
final class ProductUser
{
    private const MAX_PART_LENGTH = 100;

    /** @var string */
    private $product;

    /** @var string */
    private $customer;

    /** @var string */
    private $person;

    private function __construct(string $product, string $customer, string $person)
    {
        $this->product = $product;
        $this->customer = $customer;
        $this->person = $person;
    }

    /**
     * @param string|int|mixed $customer
     * @param string|int|mixed $person
     * @return self|null null when the customer or person is missing or unusable; the caller is then refused
     * @throws \InvalidArgumentException when the product is invalid: that is a settings mistake, not a user one
     */
    public static function create(string $product, $customer, $person): ?self
    {
        // GAITH reserves `member:<id>` for its own signed-in users.
        if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $product) || $product === 'member') {
            throw new \InvalidArgumentException(
                'product must be a short lowercase name such as "qhse" or "erp", and not "member"'
            );
        }

        $customer = self::part($customer);
        $person = self::part($person);
        if ($customer === null || $person === null) {
            return null;
        }

        return new self($product, $customer, $person);
    }

    /**
     * Ids only: no ':' (it separates the parts, so `7:42` as a customer would let one
     * user's id pass for another's), no spaces, nothing that is not a string or int.
     *
     * @param mixed $value
     */
    private static function part($value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_PART_LENGTH || !preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            return null;
        }

        return $value;
    }

    public function product(): string
    {
        return $this->product;
    }

    public function customer(): string
    {
        return $this->customer;
    }

    public function person(): string
    {
        return $this->person;
    }

    public function externalUserId(): string
    {
        return $this->product . ':' . $this->customer . ':' . $this->person;
    }
}
