<?php

namespace Osos\Gaith\Sdk\Laravel\Identity;

use Illuminate\Http\Request;
use Osos\Gaith\Sdk\Identity\ProductUser;

/**
 * Turns the host application's signed-in user into a ProductUser.
 *
 * The result decides whose history a chat lands in and, later, whose identity the
 * signed proof carries, so it must come from the server-side session - never from
 * anything the browser sends (a body field, a query string, a `selectedCompany` header).
 */
interface ProductUserResolver
{
    /**
     * @return ProductUser|null null when the caller cannot be identified; the request is then refused
     */
    public function resolve(Request $request): ?ProductUser;
}
