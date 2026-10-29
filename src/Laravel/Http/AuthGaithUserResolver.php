<?php

namespace Osos\Gaith\Sdk\Laravel\Http;

use Illuminate\Http\Request;

/**
 * Default resolver: the request's authenticated user (default guard), prefixed
 * with `gaith-chatbot.widget.user_prefix`.
 */
final class AuthGaithUserResolver implements GaithUserResolver
{
    public function resolve(Request $request): ?string
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $id = $user->getAuthIdentifier();
        if ($id === null || $id === '') {
            return null;
        }

        return config('gaith-chatbot.widget.user_prefix', 'user-') . $id;
    }
}
