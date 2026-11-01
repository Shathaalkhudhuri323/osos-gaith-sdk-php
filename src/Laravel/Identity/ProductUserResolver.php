<?php

namespace Osos\Gaith\Sdk\Laravel\Identity;

use Illuminate\Http\Request;
use Osos\Gaith\Sdk\Identity\ProductUser;

/**
 * Finds out who is chatting for a request: the product, the customer and the person.
 *
 * The SDK comes with ConfiguredProductUserResolver, which reads this from settings.
 * A product whose users cannot be described with those settings can write its own
 * class that implements this interface and name it in `gaith-chatbot.identity.resolver`.
 *
 * Whatever the class does, it must use the signed-in user on the server and never
 * anything the browser sends, such as a body field or a "selected company" header.
 */
interface ProductUserResolver
{
    /**
     * @return ProductUser|null null when we cannot tell who the user is; the request is then refused
     */
    public function resolve(Request $request): ?ProductUser;
}
