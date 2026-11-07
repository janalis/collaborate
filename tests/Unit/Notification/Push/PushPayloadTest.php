<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\Push\PushPayload;
use Janalis\Collaborate\Notification\RecipientLocaleProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(PushPayload::class)]
#[UsesClass(Notification::class)]
#[UsesClass(NotificationCatalog::class)]
final class PushPayloadTest extends TestCase
{
    public function testWithoutATranslatorTheSentenceIsFilledAsItIs(): void
    {
        self::assertSame(
            [
                'id' => 4,
                'title' => 'Ding',
                'body' => 'Bob shared Song',
                'url' => '/x',
                'icon' => '/i.png',
                'tag' => 'collaborate-notification-4',
            ],
            self::decoded(new PushPayload(self::catalog(), 'en', 'Ding', '/i.png')->of(self::notification())),
        );
    }

    public function testTranslatesInTheLanguageOfTheReaderOrTheDefaultOne(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->exactly(4))
            ->method('trans')
            ->willReturnCallback(static fn(
                string $message,
                array $parameters,
                ?string $domain,
                ?string $locale,
            ): string => \sprintf('%s|%s|%s|%s', $message, json_encode($parameters), $domain, $locale));
        $locales = $this->createMock(RecipientLocaleProviderInterface::class);
        $locales
            ->expects($this->exactly(2))
            ->method('locale')
            ->willReturnCallback(static fn(int $recipient): ?string => 3 === $recipient ? 'fr' : null);
        $payload = new PushPayload(self::catalog(), 'de', 'Ding', translator: $translator, locales: $locales);
        $french = self::decoded($payload->of(self::notification()));
        self::assertSame('%name% shared %record%|{"%name%":"Bob","%record%":"Song"}|library|fr', $french['body']);
        self::assertSame('Ding|[]|collaborate|fr', $french['title']);
        self::assertNull($french['icon']);
        $german = self::decoded($payload->of(self::notification(7)));
        self::assertSame('Ding|[]|collaborate|de', $german['title']);
    }

    public function testAnEmptyPushOrATypeNoLongerConfiguredSaysNothing(): void
    {
        self::assertNull(new PushPayload(self::catalog(), payload: 'none')->of(self::notification()));
        $gone = self::decoded(new PushPayload(new NotificationCatalog())->of(self::notification()));
        self::assertSame('', $gone['body']);
        self::assertSame('New notification', $gone['title']);
        // Its JSON keeps slashes and non-ascii letters as they are.
        self::assertStringContainsString(
            '"url":"/x"',
            (string) new PushPayload(self::catalog())->of(self::notification()),
        );
        self::assertStringContainsString(
            '“é”',
            (string) new PushPayload(
                new NotificationCatalog([], ['shared' => [
                    'topic' => 't',
                    'sentence' => '“é”',
                    'label' => null,
                    'icon' => null,
                    'domain' => 'm',
                ]]),
            )->of(self::notification()),
        );
    }

    private static function catalog(): NotificationCatalog
    {
        return new NotificationCatalog(['shares' => [
            'label' => 'Shares',
            'description' => null,
            'default' => true,
            'mandatory' => false,
            'domain' => 'messages',
        ]], ['shared' => [
            'topic' => 'shares',
            'sentence' => '%name% shared %record%',
            'label' => null,
            'icon' => null,
            'domain' => 'library',
        ]]);
    }

    private static function notification(int $recipient = 3): Notification
    {
        $notification = new Notification(
            $recipient,
            'shares',
            'shared',
            ['%name%' => 'Bob', '%record%' => 'Song'],
            '/x',
            new \DateTimeImmutable(),
        );
        new \ReflectionProperty(Notification::class, 'id')->setValue($notification, 4);

        return $notification;
    }

    /** @return array<array-key, mixed> */
    private static function decoded(?string $json): array
    {
        // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next line narrows it
        $decoded = json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
