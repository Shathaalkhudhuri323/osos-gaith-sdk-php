<?php

namespace Osos\Gaith\Sdk\Laravel\Http;

use Illuminate\Http\Request;

/**
 * Maps the host application's signed-in user to the GAITH external_user_id.
 *
 * That id partitions conversation history inside GAITH, so it must come from the
 * server-side session - never from anything the browser sends.
 */
interface GaithUserResolver
{
    /**
     * @return string|null null when the caller cannot be identified; the request is then refused
     */
    public function resolve(Request $request): ?string;
}
