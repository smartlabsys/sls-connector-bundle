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
}
