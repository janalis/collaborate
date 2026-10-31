<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional;

use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\EasyAdmin\UpdatesSubscriber;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\RenewSubscriber;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\Arrays;
use Janalis\Collaborate\Tests\Fixtures\TestKernel;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The page's one subscriber token, through the layer, the routes and the hub's token factory: a Mercure 1.0
 * hub accepts only access tokens carrying the claims it trusts (`iss`, `aud`…), so the token must carry the
 * claims configured for the hub.
 */
#[UsesClass(ChangeCollector::class)]
#[UsesClass(CollaborateFormExtension::class)]
#[UsesClass(UpdatesSubscriber::class)]
#[UsesClass(DraftSave::class)]
#[UsesClass(DraftJournal::class)]
#[UsesClass(RecordIdentifier::class)]
#[CoversClass(HubController::class)]
#[CoversClass(CursorController::class)]
#[CoversClass(CollaborateExtension::class)]
#[UsesClass(CollaborateBundle::class)]
#[UsesClass(PageGrant::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(Grant::class)]
#[UsesClass(HubGuard::class)]
#[UsesClass(HubPublisher::class)]
#[UsesClass(RenewSubscriber::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(Admission::class)]
final class MercureTokenTest extends CollaborateWebTestCase
{
    public function testTheSessionTokenIsAnAccessTokenForTheHubCarryingItsConfiguredClaims(): void
    {
        static::$collaborate = ['drafts' => true];
        $client = $this->signIn();
        $data = $this->session($client, ['updates' => true]);

        self::assertSame(TestKernel::HUB_URL, $data['url']);
        self::assertSame(15, $data['userId']);
        $topics = Arrays::at($data, 'topics');
        self::assertCount(2, $topics);
        self::assertIsString($topics[0]);
        self::assertMatchesRegularExpression('/^urn:collaborate:cursors:[a-f0-9]{64}$/', $topics[0]);
        self::assertSame('urn:collaborate:updates', $topics[1]);

        self::assertIsString($data['token']);
        $parts = explode('.', $data['token']);
        self::assertCount(3, $parts);
        self::assertSame('at+jwt', $this->segment($parts[0])['typ'] ?? null);
        $payload = $this->segment($parts[1]);
        foreach (TestKernel::JWT_CLAIMS as $claim => $value) {
            self::assertSame($value, $payload[$claim] ?? null, $claim);
        }
        self::assertIsNumeric($payload['exp'] ?? null);
        self::assertEqualsWithDelta(time() + 300, $payload['exp'], 5);
        self::assertArrayNotHasKey('mercure', $payload);
        self::assertSame(
            [[
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => ['subscribe'],
                'topics' => array_map(static fn(mixed $topic): array => ['match' => $topic], $topics),
            ]],
            $payload['authorization_details'] ?? null,
        );
    }

    public function testTheSubscriptionLifetimeAndTopicPrefixAreConfigured(): void
    {
        static::$collaborate = ['hub' => ['subscription_ttl' => 60, 'topic_prefix' => 'https://app.example.com/']];
        $data = $this->session($this->signIn(), ['updates' => true]);
        self::assertIsArray($data['topics']);
        // Updates follow shared drafts, which are off.
        self::assertCount(1, $data['topics']);
        self::assertIsString($data['topics'][0]);
        self::assertStringStartsWith('https://app.example.com/cursors:', $data['topics'][0]);
        self::assertIsString($data['token']);
        $payload = $this->segment(explode('.', $data['token'])[1]);
        self::assertIsNumeric($payload['exp'] ?? null);
        self::assertEqualsWithDelta(time() + 60, $payload['exp'], 5);
    }

    public function testTheLayerIsRenderedForCollaboratorsOnly(): void
    {
        $client = $this->signIn('reader');
        $crawler = $client->request('GET', '/admin/page');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-collaborate-layer]'));
        self::assertCount(0, $crawler->filter('[data-collaborate-presence]'));
        $client->request(
            'POST',
            '/admin/cursors/session',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );
        self::assertResponseStatusCodeSame(403);

        static::ensureKernelShutdown();
        $crawler = $this->signIn()->request('GET', '/admin/page');
        self::assertSame('Also on this page', $crawler->filter('[data-collaborate-presence]')->attr('aria-label'));
        $layer = $crawler->filter('[data-collaborate-layer]');
        self::assertSame('/admin/cursors/session', $layer->attr('data-session-url'));
        self::assertSame('/admin/cursors/events', $layer->attr('data-event-url'));
        self::assertNull($layer->attr('data-updates'));
    }

    public function testTheEventsRouteAnswersNotFoundWhenCursorsAreDisabled(): void
    {
        static::$collaborate = ['cursors' => false];
        $client = $this->signIn();
        $crawler = $client->request('GET', '/admin/page');
        self::assertNull($crawler->filter('[data-collaborate-layer]')->attr('data-event-url'));
        $client->request(
            'POST',
            '/admin/cursors/events',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private function session(KernelBrowser $client, array $body): array
    {
        $crawler = $client->request('GET', '/admin/page');
        self::assertResponseIsSuccessful();
        $layer = $crawler->filter('[data-collaborate-layer]');
        $client->request(
            'POST',
            (string) $layer->attr('data-session-url'),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => (string) $layer->attr('data-csrf')],
            content: json_encode(['grant' => $layer->attr('data-grant')] + $body, \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));

        return Arrays::json((string) $client->getResponse()->getContent());
    }

    /** @return array<array-key, mixed> */
    private function segment(string $segment): array
    {
        return Arrays::json((string) base64_decode(strtr($segment, '-_', '+/'), true));
    }
}
