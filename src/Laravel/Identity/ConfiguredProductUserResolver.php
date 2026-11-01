<?php

namespace Osos\Gaith\Sdk\Laravel\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Osos\Gaith\Sdk\Identity\ProductUser;

/**
 * Default resolver, driven by `gaith-chatbot.identity`. Each of `customer` and
 * `person` names where its value comes from:
 *
 *   user:<path>     an attribute of the signed-in user; dots follow relations
 *                   (QHSE: user:employee.centralDBCompanyID)
 *   config:<key>    a config value the host sets server-side, e.g. in its tenant
 *                   middleware (ERP: config:database.connections.mysql.database)
 *   value:<text>    a fixed value, for a deployment that serves one customer
 *
 * `person` left empty means the signed-in user's auth identifier.
 *
 * There is deliberately no source that reads the request: the customer would then
 * be whatever the browser says it is.
 */
final class ConfiguredProductUserResolver implements ProductUserResolver
{
    public function resolve(Request $request): ?ProductUser
    {
        $settings = (array) config('gaith-chatbot.identity', []);

        $product = $settings['product'] ?? null;
        if (!is_string($product) || $product === '') {
            throw new \InvalidArgumentException('gaith-chatbot.identity.product is not set (e.g. "qhse" or "erp")');
        }
        $customerSource = self::source($settings['customer'] ?? null, 'customer');
        $personSource = self::source($settings['person'] ?? null, 'person');

        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $customer = $customerSource === null ? null : self::read($customerSource, $user);
        $person = $personSource === null ? $user->getAuthIdentifier() : self::read($personSource, $user);

        $resolved = ProductUser::create($product, $customer, $person);
        if ($resolved === null) {
            // Usually a signed-in user with no employee record, or a source pointing at
            // the wrong field. The values themselves stay out of the log.
            Log::warning('GAITH chat: could not identify the signed-in user from gaith-chatbot.identity', [
                'customer_found' => $customer !== null && $customer !== '',
                'person_found' => $person !== null && $person !== '',
            ]);
        }

        return $resolved;
    }

    /**
     * @param mixed $spec
     * @return array{0: string, 1: string}|null [kind, name]; null only for an unset person
     */
    private static function source($spec, string $setting): ?array
    {
        if ($spec === null || $spec === '') {
            if ($setting === 'person') {
                return null;
            }
            throw new \InvalidArgumentException(
                "gaith-chatbot.identity.{$setting} is not set (e.g. user:companyId, config:<key> or value:<text>)"
            );
        }

        if (!is_string($spec) || !preg_match('/^(user|config|value):(.+)$/', trim($spec), $m)) {
            throw new \InvalidArgumentException(
                "gaith-chatbot.identity.{$setting} must start with user:, config: or value:"
            );
        }

        return [$m[1], $m[2]];
    }

    /**
     * @param array{0: string, 1: string} $source
     * @param mixed $user
     * @return mixed
     */
    private static function read(array $source, $user)
    {
        [$kind, $name] = $source;

        switch ($kind) {
            case 'user':
                return data_get($user, $name);
            case 'config':
                return config($name);
            default:
                return $name;
        }
    }
}
