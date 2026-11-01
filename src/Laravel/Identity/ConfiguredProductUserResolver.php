<?php

namespace Osos\Gaith\Sdk\Laravel\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Osos\Gaith\Sdk\Identity\ProductUser;

/**
 * Works out who is chatting, using only the settings in `gaith-chatbot.identity`, so a
 * product (QHSE, the ERP...) does not need to write any code for it.
 *
 * The settings say where to find the customer and the person. Each one starts with a
 * word that tells us where to look:
 *
 *   user:<field>    read a field of the signed-in user. Dots go into related records,
 *                   e.g. QHSE uses "user:employee.centralDBCompanyID".
 *   config:<key>    read a Laravel config value that the product sets on the server,
 *                   e.g. the ERP uses "config:database.connections.mysql.database",
 *                   which its tenant middleware sets to the customer's database.
 *   value:<text>    use this fixed value, for an installation with only one customer.
 *
 * If the person setting is empty, we use the signed-in user's normal id.
 *
 * There is on purpose no way to read these values from the request: then the browser
 * could simply claim to be another customer.
 */
final class ConfiguredProductUserResolver implements ProductUserResolver
{
    public function resolve(Request $request): ?ProductUser
    {
        $settings = (array) config('gaith-chatbot.identity', []);

        // Check the settings first, so a mistake there shows up as a clear error
        // rather than as every user being refused.
        $product = $settings['product'] ?? null;
        if (!is_string($product) || $product === '') {
            throw new \InvalidArgumentException('gaith-chatbot.identity.product is not set (e.g. "qhse" or "erp")');
        }
        $customerSource = self::source($settings['customer'] ?? null, 'customer');
        $personSource = self::source($settings['person'] ?? null, 'person');

        $user = $request->user();
        if ($user === null) {
            return null; // nobody is signed in
        }

        $customer = $customerSource === null ? null : self::read($customerSource, $user);
        $person = $personSource === null ? $user->getAuthIdentifier() : self::read($personSource, $user);

        $resolved = ProductUser::create($product, $customer, $person);
        if ($resolved === null) {
            // This usually means the user has no employee record, or a setting points at the
            // wrong field. We only log whether each value was found, not the values themselves.
            Log::warning('GAITH chat: could not identify the signed-in user from gaith-chatbot.identity', [
                'customer_found' => $customer !== null && $customer !== '',
                'person_found' => $person !== null && $person !== '',
            ]);
        }

        return $resolved;
    }

    /**
     * Reads one setting such as "user:employee.centralDBCompanyID" and splits it into
     * where to look ("user") and what to read ("employee.centralDBCompanyID").
     *
     * @param mixed $spec
     * @return array{0: string, 1: string}|null null only when the person setting is left empty
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
     * Looks up the value a setting points to.
     *
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
