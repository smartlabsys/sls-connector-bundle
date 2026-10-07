<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Contract;

use Smartlabsys\SlsConnectorBundle\Test\SlsContractTestCase;

/** The contract suite against the demo app — the same class an app extends in its own CI. */
final class DemoAppContractTest extends SlsContractTestCase
{
    protected function contractSeedParameters(): array
    {
        return ['lab_name' => 'Contract lab'];
    }

    /** `claim.code` reaches the provisioner; an unknown code is a 422, not a new tenant. */
    public function testUnknownClaimCodeIsRejected(): void
    {
        $token = $this->serviceToken(['sls:provision']);
        $orgId = $this->uid();
        $body  = $this->contractTenantRequest($orgId) + ['claim' => ['code' => ' abcd-efgh-jklm ']];

        foreach (['/sls/provisioning/tenants/preview', '/sls/provisioning/tenants'] as $path) {
            [$status, $answer] = $this->call('POST', $path, $token, $body);
            self::assertSame(422, $status, $path);
            self::assertSame('invalid_claim_code', $answer['error'], $path);
            self::assertNotSame('', $answer['message']);
        }

        // An empty code is no code: the normal lookup applies.
        $body['claim']['code'] = '  ';
        [$status, $created] = $this->call('POST', '/sls/provisioning/tenants', $token, $body);
        self::assertSame(201, $status);
        [$status] = $this->call('DELETE', '/sls/provisioning/tenants/' . $created['tenant_id'], $token);
        self::assertSame(204, $status);
    }
}
