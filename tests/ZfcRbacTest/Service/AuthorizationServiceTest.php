<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace ZfcRbacTest\Service;

use ZfcRbac\Identity\IdentityInterface;
use ZfcRbac\Role\InMemoryRoleProvider;
use ZfcRbac\Service\AuthorizationService;
use ZfcRbac\Service\RoleService;
use ZfcRbacTest\Asset\SimpleAssertion;
use ZfcRbac\Assertion\AssertionPluginManager;
use Zend\ServiceManager\Config;

/**
 * @covers \ZfcRbac\Service\AuthorizationService
 */
class AuthorizationServiceTest extends \PHPUnit_Framework_TestCase
{
    public function grantedProvider()
    {
        return [
            // Simple is granted
            [
                'guest',
                'read',
                null,
                true
            ],

            // Simple is allowed from parent
            [
                'member',
                'read',
                null,
                true
            ],

            // Simple is refused
            [
                'guest',
                'write',
                null,
                false
            ],

            // Simple is refused from parent
            [
                'guest',
                'delete',
                null,
                false
            ],

            // Simple is refused from assertion map
            [
                'admin',
                'delete',
                false,
                false,
                [
                    'delete' => 'ZfcRbacTest\Asset\SimpleAssertion'
                ]
            ],

            // Simple is accepted from assertion map
            [
                'admin',
                'delete',
                true,
                true,
                [
                    'delete' => 'ZfcRbacTest\Asset\SimpleAssertion'
                ]
            ],

            // Simple is refused from no role
            [
                [],
                'read',
                null,
                false
            ],
        ];
    }

    /**
     * @dataProvider grantedProvider
     */
    public function testGranted($role, $permission, $context = null, $isGranted, $assertions = array())
    {
        $roleConfig = [
            'admin' => [
                'children'    => ['member'],
                'permissions' => ['delete']
            ],
            'member' => [
                'children'    => ['guest'],
                'permissions' => ['write']
            ],
            'guest' => [
                'permissions' => ['read']
            ]
        ];

        $assertionPluginConfig = [
            'invokables' => [
                'ZfcRbacTest\Asset\SimpleAssertion' => 'ZfcRbacTest\Asset\SimpleAssertion'
            ]
        ];

        $identity = $this->getMock('ZfcRbac\Identity\IdentityInterface');
        $identity->expects($this->once())->method('getRoles')->will($this->returnValue((array) $role));

        $identityProvider = $this->getMock('ZfcRbac\Identity\IdentityProviderInterface');
        $identityProvider->expects($this->any())
                         ->method('getIdentity')
                         ->will($this->returnValue($identity));

        $roleService            = new RoleService($identityProvider, new InMemoryRoleProvider($roleConfig));
        $assertionPluginManager = new AssertionPluginManager(new Config($assertionPluginConfig));
        $authorizationService   = new AuthorizationService($roleService, $assertionPluginManager);

        $authorizationService->setAssertions($assertions);

        $this->assertEquals($isGranted, $authorizationService->isGranted($permission, $context));
    }

    public function testDoNotCallAssertionIfThePermissionIsNotGranted()
    {
        $role = $this->getMock('Rbac\Role\RoleInterface');
        $role->expects($this->once())->method('hasPermission')->will($this->returnValue(false));

        $roleService = $this->getMock('ZfcRbac\Service\RoleService', [], [], '', false);
        $roleService->expects($this->once())->method('getIdentityRoles')->will($this->returnValue([$role]));

        $assertionPluginManager = $this->getMock('ZfcRbac\Assertion\AssertionPluginManager', [], [], '', false);
        $assertionPluginManager->expects($this->never())->method('get');

        $authorizationService = new AuthorizationService($roleService, $assertionPluginManager);

        $this->assertFalse($authorizationService->isGranted('foo', false));
    }

    public function testThrowExceptionForInvalidAssertion()
    {
        $role = $this->getMock('Rbac\Role\RoleInterface');
        $role->expects($this->once())->method('hasPermission')->will($this->returnValue(true));

        $roleService = $this->getMock('ZfcRbac\Service\RoleService', [], [], '', false);
        $roleService->expects($this->once())->method('getIdentityRoles')->will($this->returnValue([$role]));

        $assertionPluginManager = $this->getMock('ZfcRbac\Assertion\AssertionPluginManager', [], [], '', false);
        $authorizationService   = new AuthorizationService($roleService, $assertionPluginManager);

        $this->setExpectedException('ZfcRbac\Exception\InvalidArgumentException');

        $authorizationService->setAssertion('foo', new \stdClass());
        $authorizationService->isGranted('foo');
    }

    public function testDynamicAssertions()
    {
        $role = $this->getMock('Rbac\Role\RoleInterface');
        $role->expects($this->exactly(2))->method('hasPermission')->will($this->returnValue(true));

        $identity = $this->getMock('ZfcRbac\Identity\IdentityInterface');

        $roleService = $this->getMock('ZfcRbac\Service\RoleService', [], [], '', false);
        $roleService->expects($this->exactly(2))->method('getIdentity')->will($this->returnValue($identity));
        $roleService->expects($this->exactly(2))->method('getIdentityRoles')->will($this->returnValue([$role]));

        $assertionPluginManager = $this->getMock('ZfcRbac\Assertion\AssertionPluginManager', [], [], '', false);
        $authorizationService   = new AuthorizationService($roleService, $assertionPluginManager);

        // Using a callable
        $called = false;

        $authorizationService->setAssertion('foo', 
            function(IdentityInterface $expectedIdentity = null) use($identity, &$called) {
                $this->assertSame($expectedIdentity, $identity);
                $called = true;

                return false;
            }
        );
        $this->assertFalse($authorizationService->isGranted('foo'));
        $this->assertTrue($called);

        // Using an assertion object
        $assertion = new SimpleAssertion();
        $authorizationService->setAssertion('foo', $assertion);

        $this->assertFalse($authorizationService->isGranted('foo', false));
        $this->assertTrue($assertion->getCalled());
    }

    public function testAssertionMap()
    {
        $roleService            = $this->getMock('ZfcRbac\Service\RoleService', [], [], '', false);
        $assertionPluginManager = $this->getMock('ZfcRbac\Assertion\AssertionPluginManager', [], [], '', false);
        $authorizationService   = new AuthorizationService($roleService, $assertionPluginManager);

        $authorizationService->setAssertions(['foo' => 'bar', 'bar' => 'foo']);

        $this->assertTrue($authorizationService->hasAssertion('foo'));
        $this->assertTrue($authorizationService->hasAssertion('bar'));

        $authorizationService->setAssertion('bar', null);
        
        $this->assertFalse($authorizationService->hasAssertion('bar'));
    }
}
