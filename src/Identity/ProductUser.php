<?php

namespace Osos\Gaith\Sdk\Identity;

/**
 * The person who is chatting, as the product (QHSE, the ERP...) knows them.
 *
 * It holds three things:
 *   - product:  which product they are using, e.g. "qhse"
 *   - customer: which customer (company) of that product they belong to, e.g. "7"
 *   - person:   who they are inside that customer, e.g. employee "42"
 *
 * Joined together these make the user id we send to GAITH, e.g. "qhse:7:42".
 * GAITH keeps each user's chat history under that id. Because the customer is part of
 * the id, employee 42 at one customer can never see the chats of employee 42 at another.
 *
 * All three values are read on the server from the signed-in user, never from the browser.
 */
final class ProductUser
{
    // Customer and person ids longer than this are refused; real ids are much shorter.
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
     * Builds the user, or returns null if the customer or person is missing or looks wrong.
     * The caller then refuses the request, because we cannot tell who the user is.
     *
     * A bad product name throws instead: that is a mistake in the settings, not something
     * the user did, so it should be loud and fixed by whoever set the product up.
     *
     * @param string|int|mixed $customer
     * @param string|int|mixed $person
     */
    public static function create(string $product, $customer, $person): ?self
    {
        // "member" is not allowed because GAITH already uses "member:..." for its own users.
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
     * Cleans one part of the id and checks it is a plain id: letters, digits, dot, dash or
     * underscore. A colon is not allowed because it separates the parts; otherwise a customer
     * called "7:42" could make one user's id look like another's.
     *
     * @param mixed $value
     */
    private static function part($value): ?string
    {
        // Ids often come from the database as numbers.
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

    /** The id GAITH files this user's chats under, e.g. "qhse:7:42". */
    public function externalUserId(): string
    {
        return $this->product . ':' . $this->customer . ':' . $this->person;
    }
}
