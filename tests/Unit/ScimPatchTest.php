<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimGroup;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;
use Smartlabsys\SlsConnectorBundle\Scim\ScimFilter;
use Smartlabsys\SlsConnectorBundle\Scim\ScimPatch;

final class ScimPatchTest extends TestCase
{
    public function testUserPathOperations(): void
    {
        $user = new ScimUser(userName: 'ana@example.com', givenName: 'Ana', roles: ['demo:viewer']);

        ScimPatch::applyToUser($user, $this->ops([
            ['op' => 'replace', 'path' => 'active', 'value' => false],
            ['op' => 'replace', 'path' => 'name.familyName', 'value' => 'Test'],
            ['op' => 'add', 'path' => ScimFilter::SLS_ROLES, 'value' => [['value' => 'demo:admin']]],
            ['op' => 'remove', 'path' => ScimFilter::SLS_ROLES, 'value' => ['demo:viewer']],
            ['op' => 'replace', 'path' => 'locale', 'value' => 'sr'],
        ]));

        self::assertFalse($user->active);
        self::assertSame('Ana', $user->givenName);
        self::assertSame('Test', $user->familyName);
        self::assertSame(['demo:admin'], array_values($user->roles));
        self::assertSame('sr', $user->locale);
    }

    public function testUserNoPathValueIsAPartialResource(): void
    {
        $user = new ScimUser(userName: 'ana@example.com');

        ScimPatch::applyToUser($user, $this->ops([
            ['op' => 'replace', 'value' => ['active' => false, 'displayName' => 'Ana T', 'name' => ['givenName' => 'Ana']]],
        ]));

        self::assertFalse($user->active);
        self::assertSame('Ana T', $user->displayName);
        self::assertSame('Ana', $user->givenName);
    }

    public function testUserNameCannotBeRemoved(): void
    {
        $this->expectScimError('mutability', fn () => ScimPatch::applyToUser(new ScimUser(userName: 'a'), $this->ops([['op' => 'remove', 'path' => 'userName']])));
    }

    public function testUnknownPathIsRejected(): void
    {
        $this->expectScimError('invalidPath', fn () => ScimPatch::applyToUser(new ScimUser(userName: 'a'), $this->ops([['op' => 'replace', 'path' => 'password', 'value' => 'x']])));
    }

    public function testInvalidPatchBody(): void
    {
        $this->expectScimError('invalidSyntax', fn () => ScimPatch::operations(['Operations' => []]));
        $this->expectScimError(null, fn () => ScimPatch::operations(['schemas' => [ScimPatch::SCHEMA], 'Operations' => [['op' => 'move', 'path' => 'active']]]));
    }

    public function testGroupMembers(): void
    {
        $group = new ScimGroup(displayName: 'Lab', members: ['u1']);

        ScimPatch::applyToGroup($group, $this->ops([
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u2'], ['value' => 'u3']]],
            ['op' => 'remove', 'path' => 'members[value eq "u1"]'],
            ['op' => 'replace', 'path' => 'displayName', 'value' => 'Lab 2'],
        ]));

        self::assertSame(['u2', 'u3'], array_values($group->members));
        self::assertSame('Lab 2', $group->displayName);
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return list<array{op: string, path: ?string, value: mixed}>
     */
    private function ops(array $operations): array
    {
        return ScimPatch::operations(['schemas' => [ScimPatch::SCHEMA], 'Operations' => $operations]);
    }

    private function expectScimError(?string $scimType, callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected a SCIM error');
        } catch (ContractException $e) {
            self::assertSame(400, $e->status);
            if ($scimType !== null) {
                self::assertSame($scimType, $e->scimType);
            }
        }
    }
}
