<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional;

use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\DraftValues;
use Janalis\Collaborate\Draft\EventValidator;
use Janalis\Collaborate\Draft\FieldDefinitions;
use Janalis\Collaborate\EasyAdmin\UpdatesSubscriber;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\RenewSubscriber;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

/** A page renewing its page grant in the background leaves the flash messages to the page the user sees next. */
#[UsesClass(ChangeCollector::class)]
#[UsesClass(CollaborateFormExtension::class)]
#[UsesClass(UpdatesSubscriber::class)]
#[UsesClass(DraftSave::class)]
#[UsesClass(DraftJournal::class)]
#[UsesClass(DraftValues::class)]
#[UsesClass(EventValidator::class)]
#[UsesClass(FieldDefinitions::class)]
#[UsesClass(RecordIdentifier::class)]
#[CoversClass(RenewSubscriber::class)]
#[UsesClass(CollaborateBundle::class)]
#[UsesClass(PageGrant::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(Grant::class)]
#[UsesClass(HubPublisher::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(CollaborateExtension::class)]
final class RenewTest extends CollaborateWebTestCase
{
    public function testARenewalRenderKeepsTheFlashesAnotherTabQueued(): void
    {
        $client = $this->signIn();
        $this->queueFlash($client, 'Saved in another tab');

        $crawler = $client->request('GET', '/admin/page', server: ['HTTP_X_COLLABORATE_RENEW' => '1']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Saved in another tab', (string) $client->getResponse()->getContent());
        self::assertNotSame('', (string) $crawler->filter('[data-collaborate-layer]')->attr('data-grant'));
        $client->request('GET', '/admin/page');
        self::assertStringContainsString('Saved in another tab', (string) $client->getResponse()->getContent());
        $client->request('GET', '/admin/page');
        self::assertStringNotContainsString('Saved in another tab', (string) $client->getResponse()->getContent());
    }

    public function testAnOrdinaryRenderReadsTheFlashes(): void
    {
        $client = $this->signIn();
        $this->queueFlash($client, 'Shown once');

        $client->request('GET', '/admin/page');
        self::assertStringContainsString('Shown once', (string) $client->getResponse()->getContent());
        $client->request('GET', '/admin/page', server: ['HTTP_X_COLLABORATE_RENEW' => '1']);
        $client->request('GET', '/admin/page');
        self::assertStringNotContainsString('Shown once', (string) $client->getResponse()->getContent());
    }

    private function queueFlash(KernelBrowser $client, string $message): void
    {
        $factory = $client->getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $session = $factory->createSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $cookie = $client->getCookieJar()->get($session->getName());
        self::assertNotNull($cookie);
        $session->setId($cookie->getValue());
        $session->start();
        $session->getFlashBag()->add('success', $message);
        $session->save();
    }
}
