<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Twig;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\NotificationRepository;
use Janalis\Collaborate\Twig\NotificationRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(NotificationRuntime::class)]
#[CoversClass(NotificationRepository::class)]
#[UsesClass(NotificationAccess::class)]
#[UsesClass(NotificationCatalog::class)]
#[UsesClass(Notification::class)]
final class NotificationRuntimeTest extends TestCase
{
    public function testTheBellListsTheLatestTwentyTheLatestFirstWithTheCounts(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->once())
            ->method('findBy')
            ->with(['recipient' => 15], ['createdAt' => 'DESC', 'id' => 'DESC'], 20)
            ->willReturn([]);
        $repository->method('count')->willReturnCallback(static fn(array $criteria): int => \array_key_exists(
            'readAt',
            $criteria,
        )
                ? 2
                : 5);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $twig = new Environment(
            new ArrayLoader([
                '@JanalisCollaborate/notifications/bell.html.twig' => '{{ notifications|length }} {{ unread }}/{{ total }} {{ settings.maxCount }}',
            ]),
            ['strict_variables' => true],
        );
        self::assertSame('0 2/5 99', $this->runtime(new NotificationRepository($em))->bell($twig));
    }

    public function testTheSettingsNameWhereTheScriptsAskAndWebPushOnceItHasAKey(): void
    {
        $settings = [
            'src' => 'collaborate_notifications',
            'page' => 'collaborate_notifications_page',
            'topics' => 'collaborate_notification_topics',
            'read' => 'collaborate_notifications_change?action=read',
            'unread' => 'collaborate_notifications_change?action=unread',
            'clear' => 'collaborate_notifications_change?action=clear',
            'topicsToken' => 'token:collaborate-notification-topics',
            'maxCount' => 99,
            'push' => null,
        ];
        // Off unless turned on, and on without a key.
        self::assertSame($settings, $this->runtime(publicKey: 'key')->settings());
        self::assertSame($settings, $this->runtime(push: true)->settings());
        $push = static fn(string $worker): array => [
            'key' => 'key',
            'worker' => $worker,
            'subscribe' => 'collaborate_push_subscription?action=subscribe',
            'unsubscribe' => 'collaborate_push_subscription?action=unsubscribe',
            'token' => 'token:collaborate-push',
        ];
        self::assertSame(
            array_replace($settings, ['push' => $push('collaborate_push_worker')]),
            $this->runtime(push: true, publicKey: 'key')->settings(),
        );
        self::assertSame(
            $push('/sw.js'),
            $this->runtime(push: true, publicKey: 'key', serviceWorker: '/sw.js')->settings()['push'],
        );
    }

    public function testSaysTheSentencesAsTheyAreWithoutATranslator(): void
    {
        $runtime = $this->runtime();
        self::assertSame('Bob shared', $runtime->text(self::notification()));
        self::assertSame(['shared' => 'Shared', 'told' => 'Told'], $runtime->types());
        self::assertSame(
            [
                'shares' => ['label' => 'Shares', 'description' => 'What is shared', 'mandatory' => false],
                'news' => ['label' => 'News', 'description' => null, 'mandatory' => true],
            ],
            $runtime->topics(),
        );
    }

    public function testTranslatesTheSentences(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(
                static fn(string $id, array $parameters, ?string $domain): string => (string) $domain . ':' . $id,
            );
        $runtime = $this->runtime(translator: $translator);
        self::assertSame('app:%name% shared', $runtime->text(self::notification()));
        self::assertSame(['shared' => 'app:Shared', 'told' => 'app:Told'], $runtime->types());
        self::assertSame(
            [
                'shares' => [
                    'label' => 'topics:Shares',
                    'description' => 'topics:What is shared',
                    'mandatory' => false,
                ],
                'news' => ['label' => 'topics:News', 'description' => null, 'mandatory' => true],
            ],
            $runtime->topics(),
        );
    }

    private static function notification(): Notification
    {
        return new Notification(15, 'shares', 'shared', ['%name%' => 'Bob'], null, new \DateTimeImmutable());
    }

    private function runtime(
        ?NotificationRepository $notifications = null,
        bool $push = false,
        ?string $publicKey = null,
        ?string $serviceWorker = null,
        ?TranslatorInterface $translator = null,
    ): NotificationRuntime {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new InMemoryUser('alice', null));
        $security->method('isGranted')->willReturn(true);
        $collaborators = $this->createStub(CollaboratorProviderInterface::class);
        $collaborators->method('id')->willReturn(15);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn(string $name, array $parameters = []): string => (
                $name . ([] === $parameters ? '' : '?' . http_build_query($parameters))
            ),
        );
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturnCallback(static fn(string $id): CsrfToken => new CsrfToken(
            $id,
            'token:' . $id,
        ));
        $catalog = new NotificationCatalog([
            'shares' => [
                'label' => 'Shares',
                'description' => 'What is shared',
                'default' => true,
                'mandatory' => false,
                'domain' => 'topics',
            ],
            'news' => [
                'label' => 'News',
                'description' => null,
                'default' => true,
                'mandatory' => true,
                'domain' => 'topics',
            ],
        ], [
            'unlabelled' => ['topic' => 'news', 'sentence' => 'x', 'label' => null, 'icon' => null, 'domain' => 'app'],
            'shared' => [
                'topic' => 'shares',
                'sentence' => '%name% shared',
                'label' => 'Shared',
                'icon' => null,
                'domain' => 'app',
            ],
            'told' => ['topic' => 'news', 'sentence' => 'told', 'label' => 'Told', 'icon' => null, 'domain' => 'app'],
        ]);
        $arguments = [
            new NotificationAccess($security, $collaborators, true, 'ROLE_USER'),
            $notifications ?? new NotificationRepository($this->createStub(EntityManagerInterface::class)),
            $catalog,
            $urls,
            $csrf,
        ];
        // What a test leaves out is left to the defaults of the runtime.
        $named = array_filter(
            [
                'push' => $push ? true : null,
                'publicKey' => $publicKey,
                'serviceWorker' => $serviceWorker,
                'translator' => $translator,
            ],
            static fn(mixed $value): bool => null !== $value,
        );

        return new NotificationRuntime(...$arguments, ...$named);
    }
}
