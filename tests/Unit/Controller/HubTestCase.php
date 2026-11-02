<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Controller;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\Arrays;
use Janalis\Collaborate\Tests\Fixtures\TestPlaces;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** The signed-in collaborator, its page grant and the guard every hub controller test sends requests through. */
abstract class HubTestCase extends TestCase
{
    protected const string CSRF = 'valid';

    protected TestUser $user;
    protected ?TestUser $signedIn;
    protected PageGrant $pages;
    protected DraftAccess $drafts;
    protected CollaboratorProviderInterface $collaborators;
    protected ?RateLimiterFactory $limiter = null;
    protected bool $csrf = true;
    protected ?Session $session = null;
    protected HubInterface&Stub $hub;
    protected ?TestPlaces $places = null;

    /** @var array<string, bool> */
    protected array $granted = ['ROLE_ADMIN' => true, 'IS_AUTHENTICATED_REMEMBERED' => true];

    protected function setUp(): void
    {
        $this->user = new TestUser(15, 'alice', ['ROLE_SUPER_ADMIN'], '<b>Alice</b>');
        $this->signedIn = $this->user;
        $this->pages = new PageGrant('secret', new Topics());
        $this->collaborators = new SecurityCollaboratorProvider();
        $this->drafts = $this->draftAccess(true);
        $this->hub = $this->createStub(HubInterface::class);
    }

    protected function draftAccess(bool $enabled, int $maxRequestBytes = 2_097_152): DraftAccess
    {
        return new DraftAccess(
            'secret',
            $enabled,
            new Topics(),
            $this->collaborators,
            new RoleDraftAccessChecker(),
            maxRequestBytes: $maxRequestBytes,
        );
    }

    protected function guard(): HubGuard
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn(): ?TestUser => $this->signedIn);
        $security
            ->method('isGranted')
            ->willReturnCallback(fn(mixed $attribute): bool => $this->granted[(string) $attribute] ?? false);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(
            fn(#[\SensitiveParameter] CsrfToken $token): bool => (
                $this->csrf
                && HubGuard::CSRF_TOKEN_ID === $token->getId()
                && self::CSRF === $token->getValue()
            ),
        );

        return new HubGuard(
            $security,
            $this->collaborators,
            $this->pages,
            $csrf,
            'ROLE_ADMIN',
            $this->limiter,
            $this->places,
        );
    }

    /** @param array<string, mixed> $overrides */
    protected function request(string $path, array $overrides = []): Request
    {
        $data = array_replace([
            'grant' => self::issued($this->pages->issue(
                new Request(attributes: ['_route' => 'article_index', '_place' => ['articles']]),
                15,
                time(),
                $this->user->getRoles(),
            )),
            'clientId' => '12345678-1234-1234',
            'sequence' => 1,
            'kind' => 'move',
            'style' => 'mac',
            'anchor' => ['selector' => 'input[name="title"]', 'region' => 'content', 'x' => 0.5, 'y' => 0.5],
        ], $overrides);
        $request = Request::create(
            $path,
            'POST',
            server: ['HTTP_X_CSRF_TOKEN' => self::CSRF],
            content: json_encode($data, \JSON_THROW_ON_ERROR),
        );
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();
        $request->setSession($this->session);

        return $request;
    }

    protected function draftGrant(string $id, int $userId = 15): string
    {
        return $this->drafts->issue(
            [
                'id' => $id,
                'entity' => \stdClass::class,
                'permission' => 'update',
                'new' => false,
                'draft' => null,
                'fields' => ['title' => ['type' => 'text', 'initial' => str_repeat('A', 8192)]],
            ],
            $userId,
            time(),
            $this->user->getRoles(),
        );
    }

    /** @return array<array-key, mixed> */
    protected function decode(Response $response): array
    {
        return Arrays::json((string) $response->getContent());
    }

    /** A grant the page grant issued: every page of these tests is in a place, or none is asked. */
    protected static function issued(?string $grant): string
    {
        self::assertNotNull($grant);

        return $grant;
    }
}
