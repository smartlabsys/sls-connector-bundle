<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;
use Smartlabsys\SlsConnectorBundle\Scim\ScimFilter;

final class ScimFilterTest extends TestCase
{
    public function testParsesConditionsJoinedByAnd(): void
    {
        $filter = ScimFilter::parse('userName eq "ana@example.com" and active eq true and externalId pr', ScimFilter::USER_ATTRIBUTES);

        self::assertSame([
            ['attribute' => 'userName', 'operator' => 'eq', 'value' => 'ana@example.com'],
            ['attribute' => 'active', 'operator' => 'eq', 'value' => true],
            ['attribute' => 'externalId', 'operator' => 'pr', 'value' => null],
        ], $filter->conditions);
        self::assertSame('ana@example.com', $filter->equalityValue('userName'));
        self::assertNull($filter->equalityValue('displayName'));
    }

    public function testExtensionAttributeAndEscapedQuotes(): void
    {
        $filter = ScimFilter::parse(ScimFilter::SLS_USER_ID . ' eq "abc" and displayName co "say \"hi\""', ScimFilter::USER_ATTRIBUTES);

        self::assertSame('abc', $filter->equalityValue(ScimFilter::SLS_USER_ID));
        self::assertSame('say "hi"', $filter->conditions[1]['value']);
    }

    public function testMatchesUserCaseInsensitively(): void
    {
        $user = new ScimUser(id: 'u1', externalId: 'ext-1', userName: 'Ana@Example.com', displayName: 'Ana Test', active: true, slsUserId: 'sls-1');

        self::assertTrue(ScimFilter::parse('userName eq "ana@example.com"', ScimFilter::USER_ATTRIBUTES)->matchesUser($user));
        self::assertTrue(ScimFilter::parse('displayName sw "ana" and active eq true', ScimFilter::USER_ATTRIBUTES)->matchesUser($user));
        self::assertTrue(ScimFilter::parse('userName ne "bob@example.com"', ScimFilter::USER_ATTRIBUTES)->matchesUser($user));
        self::assertFalse(ScimFilter::parse('active eq false', ScimFilter::USER_ATTRIBUTES)->matchesUser($user));
        self::assertFalse(ScimFilter::parse('locale pr', ScimFilter::USER_ATTRIBUTES)->matchesUser($user));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFilters(): iterable
    {
        yield 'or'                  => ['userName eq "a" or userName eq "b"'];
        yield 'unknown attribute'   => ['password eq "x"'];
        yield 'unknown operator'    => ['userName gt "a"'];
        yield 'grouping'            => ['(userName eq "a")'];
        yield 'missing value'       => ['userName eq'];
        yield 'unterminated string' => ['userName eq "a'];
        yield 'empty'               => [''];
        yield 'dangling and'        => ['userName eq "a" and'];
    }

    #[DataProvider('invalidFilters')]
    public function testRejectsUnsupportedFilters(string $filter): void
    {
        try {
            ScimFilter::parse($filter, ScimFilter::USER_ATTRIBUTES);
            self::fail('Expected invalidFilter');
        } catch (ContractException $e) {
            self::assertSame(400, $e->status);
            self::assertSame('invalidFilter', $e->scimType);
        }
    }
}
