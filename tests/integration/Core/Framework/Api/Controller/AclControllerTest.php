<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Acl\Event\AclGetAdditionalPrivilegesEvent;
use Shopware\Core\Framework\Api\Exception\MissingPrivilegeException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[Package('fundamentals@framework')]
class AclControllerTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    public function testGetPrivileges(): void
    {
        $this->getBrowser()->request('GET', '/api/_action/acl/privileges');
        $response = $this->getBrowser()->getResponse();
        $content = $response->getContent();
        static::assertIsString($content);
        $privileges = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        static::assertContains('unit:read', $privileges);
        static::assertContains('system:clear:cache', $privileges);
    }

    public function testGetAdditionalPrivileges(): void
    {
        $this->getBrowser()->request('GET', '/api/_action/acl/additional_privileges');
        $response = $this->getBrowser()->getResponse();
        $content = $response->getContent();
        static::assertIsString($content);
        $privileges = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        static::assertNotContains('unit:read', $privileges);
        static::assertContains('system:clear:cache', $privileges);
        static::assertContains('system.plugin_maintain', $privileges);
    }

    public function testGetAdditionalPrivilegesEvent(): void
    {
        $getAdditionalPrivileges = function (AclGetAdditionalPrivilegesEvent $event): void {
            $privileges = $event->getPrivileges();
            static::assertContains('system:clear:cache', $privileges);
            $privileges[] = 'my_custom_privilege';
            $event->setPrivileges($privileges);
        };
        $this->addEventListener(static::getContainer()->get('event_dispatcher'), AclGetAdditionalPrivilegesEvent::class, $getAdditionalPrivileges);

        $this->getBrowser()->request('GET', '/api/_action/acl/additional_privileges');
        $response = $this->getBrowser()->getResponse();
        $content = $response->getContent();
        static::assertIsString($content);
        $privileges = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        static::assertNotContains('unit:read', $privileges);
        static::assertContains('system:clear:cache', $privileges);
        static::assertContains('my_custom_privilege', $privileges);
    }

    public function testGetAdditionalPrivilegesNoPermission(): void
    {
        try {
            $this->authorizeBrowser($this->getBrowser(), [], []);
            $this->getBrowser()->request('GET', '/api/_action/acl/additional_privileges');
            $response = $this->getBrowser()->getResponse();
            $content = $response->getContent();

            static::assertIsString($content);
            static::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $content);
            static::assertSame(MissingPrivilegeException::MISSING_PRIVILEGE_ERROR, json_decode($content, true, 512, \JSON_THROW_ON_ERROR)['errors'][0]['code'], $content);
        } finally {
            $this->resetBrowser();
        }
    }
}
