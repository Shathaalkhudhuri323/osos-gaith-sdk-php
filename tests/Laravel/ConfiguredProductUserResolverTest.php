<?php

namespace Osos\Gaith\Sdk\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Osos\Gaith\Sdk\Laravel\GaithChatbotServiceProvider;
use Osos\Gaith\Sdk\Laravel\Identity\ConfiguredProductUserResolver;
use Osos\Gaith\Sdk\Laravel\Identity\ProductUserResolver;
use Orchestra\Testbench\TestCase;

/**
 * The resolver turns the host's signed-in user into product, customer and person
 * from settings alone, so QHSE and the ERP need no code of their own.
 */
final class ConfiguredProductUserResolverTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [GaithChatbotServiceProvider::class];
    }

    private function identity(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->app['config']->set('gaith-chatbot.identity.' . $key, $value);
        }
    }

    private function requestAs(?GenericUser $user, array $input = []): Request
    {
        $request = Request::create('/api/gaith/stream', 'POST', $input);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    /** A QHSE user: the ids live on the employee record, not on users. */
    private function qhseUser(): GenericUser
    {
        return new GenericUser([
            'id' => 900,
            'companyId' => 3,
            'employee' => (object) ['centralDBCompanyID' => 7, 'employeeSystemID' => 42],
        ]);
    }

    private function resolver(): ProductUserResolver
    {
        return $this->app->make(ProductUserResolver::class);
    }

    public function testTheServiceProviderBindsTheConfiguredResolver(): void
    {
        $this->assertInstanceOf(ConfiguredProductUserResolver::class, $this->resolver());
    }

    public function testQhseIdsComeFromTheEmployeeRecordThroughSettingsAlone(): void
    {
        $this->identity([
            'product' => 'qhse',
            'customer' => 'user:employee.centralDBCompanyID',
            'person' => 'user:employee.employeeSystemID',
        ]);

        $user = $this->resolver()->resolve($this->requestAs($this->qhseUser()));

        $this->assertSame('7', $user->customer());
        $this->assertSame('42', $user->person());
        $this->assertSame('qhse:7:42', $user->externalUserId());
    }

    public function testTheCustomerCanComeFromConfigSetByTheHostsTenantMiddleware(): void
    {
        // The ERP's tenant middleware points the mysql connection at the tenant's database.
        $this->app['config']->set('database.connections.mysql.database', 'acme_erp');
        $this->identity(['product' => 'erp', 'customer' => 'config:database.connections.mysql.database']);

        $user = $this->resolver()->resolve($this->requestAs(new GenericUser(['id' => 42])));

        $this->assertSame('erp:acme_erp:42', $user->externalUserId());
    }

    public function testAFixedCustomerSuitsASingleCustomerDeployment(): void
    {
        $this->identity(['product' => 'erp', 'customer' => 'value:acme']);

        $user = $this->resolver()->resolve($this->requestAs(new GenericUser(['id' => 42])));

        $this->assertSame('erp:acme:42', $user->externalUserId());
    }

    public function testThePersonDefaultsToTheSignedInUsersId(): void
    {
        $this->identity(['product' => 'erp', 'customer' => 'value:acme', 'person' => null]);

        $this->assertSame('42', $this->resolver()->resolve($this->requestAs(new GenericUser(['id' => 42])))->person());
    }

    public function testNoSignedInUserGivesNoUser(): void
    {
        $this->identity(['product' => 'qhse', 'customer' => 'value:acme']);

        $this->assertNull($this->resolver()->resolve($this->requestAs(null)));
    }

    public function testAUserWithoutAnEmployeeRecordGivesNoUser(): void
    {
        $this->identity([
            'product' => 'qhse',
            'customer' => 'user:employee.centralDBCompanyID',
            'person' => 'user:employee.employeeSystemID',
        ]);

        $this->assertNull($this->resolver()->resolve($this->requestAs(new GenericUser(['id' => 900]))));
    }

    public function testWhatTheBrowserSendsIsNeverUsed(): void
    {
        $this->identity([
            'product' => 'qhse',
            'customer' => 'user:employee.centralDBCompanyID',
            'person' => 'user:employee.employeeSystemID',
        ]);
        $request = $this->requestAs($this->qhseUser(), ['selectedCompany' => 99, 'customer' => 99, 'person' => 1]);
        $request->headers->set('selectedCompanyId', '99');

        $this->assertSame('qhse:7:42', $this->resolver()->resolve($request)->externalUserId());
    }

    /**
     * @dataProvider brokenSettings
     */
    public function testBrokenSettingsFailLoudlyInsteadOfRefusingEveryone(array $settings): void
    {
        $this->identity($settings);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver()->resolve($this->requestAs(new GenericUser(['id' => 42])));
    }

    public static function brokenSettings(): array
    {
        return [
            'no product' => [['product' => null, 'customer' => 'value:acme']],
            'no customer' => [['product' => 'erp', 'customer' => null]],
            'unknown source' => [['product' => 'erp', 'customer' => 'request:selectedCompany']],
            'source without a name' => [['product' => 'erp', 'customer' => 'user:']],
        ];
    }
}
