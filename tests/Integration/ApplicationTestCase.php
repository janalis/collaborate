<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Janalis\Collaborate\Draft\DraftText;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Tests\Application\Shared\AppKernel;
use Janalis\Collaborate\Tests\Application\Shared\Database;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Hub\RecordingTransport;
use Janalis\Collaborate\Tests\Fixtures\TestUserProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The bundle inside a test application (tests/Application), over HTTP: what a page renders, what its
 * scripts ask the bundle's endpoints, and what a save publishes. Each test class owns a database
 * (`collaborate_<app>_test`), recreated once.
 *
 * @phpstan-type Configuration array{grant: string, url: string, csrf: string, isNew: bool, editUrl: string, invitation: string|null, messages: array<string, string>, config: array<string, int>}
 * @phpstan-type Layer array{grant: string, csrf: string, session: string, events: string|null, updates: bool, config: array<string, mixed>}
 */
abstract class ApplicationTestCase extends WebTestCase
{
    /** @var array<class-string, true> */
    private static array $reset = [];

    protected KernelBrowser $client;

    /** @return class-string<AppKernel> */
    abstract protected static function kernel(): string;

    /** The edit page of article `$id`. */
    abstract protected function editUrl(int $id): string;

    abstract protected function newUrl(): string;

    /** The button that submits the page's form. */
    abstract protected function saveButton(bool $new): string;

    #[\Override]
    protected static function getKernelClass(): string
    {
        return static::kernel();
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        if (!isset(self::$reset[static::class])) {
            Database::reset($this->em());
            self::$reset[static::class] = true;
        }
    }

    protected function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * What the last request published, once its response was sent.
     *
     * @return list<Notice>
     */
    protected function published(): array
    {
        $transport = static::getContainer()->get(RecordingTransport::class);
        self::assertInstanceOf(RecordingTransport::class, $transport);

        return $transport->published;
    }

    /**
     * @param list<Notice> $notices
     *
     * @return list<array<string, mixed>> the payloads of the notices on `$topic`
     */
    protected static function on(array $notices, string $topic): array
    {
        $payloads = [];
        foreach ($notices as $notice) {
            if (!\in_array($topic, $notice->topics, true)) {
                continue;
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode($notice->data, true, flags: \JSON_THROW_ON_ERROR);
            $payloads[] = $payload;
        }

        return $payloads;
    }

    protected function signIn(string $username): void
    {
        $this->client->loginUser(new TestUserProvider()->loadUserByIdentifier($username));
    }

    protected function article(string $title = 'Article', string $summary = 'Start'): int
    {
        $article = new Article($title);
        $article->summary = $summary;
        $article->content = '<p>Text</p>';
        $em = $this->em();
        $em->persist($article);
        $em->flush();
        $em->clear();
        self::assertNotNull($article->id);

        return $article->id;
    }

    protected function open(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return Configuration */
    protected static function configuration(Crawler $page): array
    {
        $form = $page->filter('form[data-collaborate]');
        self::assertCount(1, $form);
        /** @var Configuration */
        return json_decode((string) $form->attr('data-collaborate'), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return Layer */
    protected static function layer(Crawler $page): array
    {
        $layer = $page->filter('[data-collaborate-layer]');
        self::assertCount(1, $layer);
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $layer->attr('data-config'), true, flags: \JSON_THROW_ON_ERROR);

        return [
            'grant' => (string) $layer->attr('data-grant'),
            'csrf' => (string) $layer->attr('data-csrf'),
            'session' => (string) $layer->attr('data-session-url'),
            'events' => $layer->attr('data-event-url'),
            'updates' => 'true' === $layer->attr('data-updates'),
            'config' => $config,
        ];
    }

    /**
     * POSTs JSON as the page scripts do.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function post(string $url, string $csrf, array $body, int $status = 200): array
    {
        $this->client->request(
            'POST',
            $url,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $csrf,
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame($status);
        $content = (string) $this->client->getResponse()->getContent();

        /** @var array<string, mixed> */
        return '' === $content || $status >= 300 ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * A draft request of the shared form `$configuration`.
     *
     * @param Configuration        $configuration
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function draft(array $configuration, string $action, array $body = [], int $status = 200): array
    {
        return $this->post(
            str_replace('__action__', $action, $configuration['url']),
            $configuration['csrf'],
            ['grant' => $configuration['grant'], ...$body],
            $status,
        );
    }

    /**
     * Appends the plain-text change of `$field` from `$before` to `$after`, as the page's text adapter does.
     *
     * @param Configuration        $configuration
     * @param array<string, mixed> $state         the joined draft
     *
     * @return array<string, mixed>
     */
    protected function type(
        array $configuration,
        array $state,
        string $field,
        string $before,
        string $after,
        int $status = 200,
    ): array {
        /** @var array{version: int|null} $current */
        $current = Arrays::field($state, $field);
        $version = $current['version'] ?? 0;

        return $this->draft(
            $configuration,
            'append',
            [
                'id' => bin2hex(random_bytes(12)),
                'field' => $field,
                'generation' => $state['generation'],
                'version' => $version,
                'seen' => $state['revision'],
                'kind' => 'operations',
                'operations' => new DraftText()->diff($before, $after, $version),
            ],
            $status,
        );
    }

    /**
     * Submits the page's form as the draft session does: with the grant and the revision it synchronized at.
     *
     * @param array<string, string> $values
     * @param array<string, mixed>  $state  the draft the page synchronized
     */
    protected function save(Crawler $page, array $values, ?array $state = null, bool $new = false): void
    {
        $form = $page->selectButton($this->saveButton($new))->form();
        $fields = $values;
        if (null !== $state) {
            $fields += [
                '_collaborate_grant' => self::configuration($page)['grant'],
                '_collaborate_revision' => (string) $state['revision'],
                '_collaborate_generation' => (string) $state['generation'],
            ];
        }
        $parameters = $form->getPhpValues();
        foreach ($fields as $name => $value) {
            $field = [];
            parse_str(rawurlencode($name) . '=' . rawurlencode($value), $field);
            $parameters = array_replace_recursive($parameters, $field);
        }
        $this->client->request($form->getMethod(), $form->getUri(), $parameters);
    }

    protected function purge(): string
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('collaborate:purge'));
        self::assertSame(0, $tester->execute([]));

        return $tester->getDisplay();
    }
}
