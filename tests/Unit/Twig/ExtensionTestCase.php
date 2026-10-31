<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Twig;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\CsrfExtension;
use Symfony\Bridge\Twig\Extension\CsrfRuntime;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/** Renders {@see CollaborateExtension} with the Twig functions a host application provides. */
abstract class ExtensionTestCase extends TestCase
{
    protected PageGrant $pages;

    protected function setUp(): void
    {
        $this->pages = new PageGrant('secret', new Topics());
    }

    protected function element(string $html): \DOMElement
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            \LIBXML_NOERROR,
        ));
        $element = $document->getElementsByTagName('body')->item(0)?->firstElementChild;
        self::assertInstanceOf(\DOMElement::class, $element);
        self::assertNull($element->nextElementSibling);

        return $element;
    }

    protected function twig(?CollaborateExtension $extension = null): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 3) . '/templates', 'JanalisCollaborate');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn(string $name): string => '/admin/' . $name);
        $twig->addExtension(new RoutingExtension($urls));
        $twig->addExtension(new TranslationExtension());
        $twig->addExtension(new CsrfExtension());
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturnCallback(static fn(string $id): CsrfToken => new CsrfToken(
            $id,
            'token:' . $id,
        ));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            CsrfRuntime::class => static fn(): CsrfRuntime => new CsrfRuntime($csrf),
        ]));
        if (null !== $extension) {
            $twig->addExtension($extension);
        }

        return $twig;
    }

    /**
     * @param list<string> $granted
     * @param array{cursors?: bool, updates?: bool, throttle?: int, heartbeat?: int, expiry?: int, maxAvatars?: int, renew?: int, colors?: list<string>} $layer
     */
    protected function extension(
        mixed $user = null,
        bool $hasRequest = true,
        bool $isGranted = true,
        bool $drafts = false,
        string $role = 'ROLE_ADMIN',
        array &$granted = [],
        array $layer = [],
    ): CollaborateExtension {
        $requests = new RequestStack();
        if ($hasRequest) {
            $requests->push(new Request(attributes: ['_route' => 'article_index']));
        }
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security
            ->method('isGranted')
            ->willReturnCallback(static function (mixed $attribute) use ($isGranted, $role, &$granted): bool {
                $granted[] = (string) $attribute;

                return $isGranted && 'ROLE_ADMIN' === $role;
            });
        $collaborators = new SecurityCollaboratorProvider();

        return new CollaborateExtension(
            $requests,
            $security,
            $collaborators,
            $this->pages,
            new DraftAccess('secret', $drafts, new Topics(), $collaborators, new RoleDraftAccessChecker()),
            $role,
            $layer,
        );
    }
}
