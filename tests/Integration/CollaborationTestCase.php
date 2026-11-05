<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Symfony\Component\Mercure\Update;

/**
 * What both test applications share with the bundle, whatever their admin: the layer, the hub session,
 * cursor events, a draft's round trip from two editors through a save and a discard, new-record drafts
 * and the purge.
 */
abstract class CollaborationTestCase extends ApplicationTestCase
{
    public function testAnAdministratorsPageCarriesTheLayerThePresenceAndTheSharedForm(): void
    {
        $this->signIn('alice');
        $page = $this->open($this->editUrl($this->article()));

        $layer = self::layer($page);
        self::assertSame('/admin/cursors/session', $layer['session']);
        self::assertSame('/admin/cursors/events', $layer['events']);
        self::assertTrue($layer['updates']);
        self::assertNotSame('', $layer['grant']);
        self::assertNotSame('', $layer['csrf']);
        self::assertSame(
            ['throttle' => 100, 'heartbeat' => 5_000, 'expiry' => 15_000, 'maxAvatars' => 5, 'renew' => 240_000],
            array_diff_key($layer['config'], ['colors' => true]),
        );
        $presence = $page->filter('[data-collaborate-presence]');
        self::assertCount(1, $presence);
        self::assertSame('Also on this page', $presence->attr('aria-label'));

        $configuration = self::configuration($page);
        self::assertSame('/admin/collaborate/__action__', $configuration['url']);
        self::assertFalse($configuration['isNew']);
        self::assertNull($configuration['invitation']);
        self::assertSame('All changes saved by %name%.', $configuration['messages']['savedBy']);
        self::assertSame(
            ['replayConnected' => 30_000, 'replayDisconnected' => 5_000, 'joinRetry' => 12_000, 'labelLength' => 255],
            $configuration['config'],
        );
        self::assertStringContainsString('__record__', $configuration['editUrl']);
        // The grant signs the field schema: each control, the rich-text field as CKEditor content, the tags.
        $schema = self::schema($configuration['grant']);
        foreach (['title', 'summary', 'status'] as $field) {
            self::assertArrayHasKey($this->formName() . '[' . $field . ']', $schema);
        }
        self::assertSame('rich', Arrays::field(['fields' => $schema], $this->formName() . '[content]')['type']);
        self::assertSame(
            'collection',
            Arrays::field(['fields' => $schema], $this->formName() . '[tags][__collection__]')['type'],
        );
        self::assertCount(1, $page->filter(\sprintf('[data-collaborate-collection="%s[tags]"]', $this->formName())));
    }

    public function testAReaderSeesThePageWithoutCollaboration(): void
    {
        $this->signIn('reader');
        $page = $this->open($this->editUrl($this->article()));

        self::assertCount(0, $page->filter('[data-collaborate-layer]'));
        self::assertCount(0, $page->filter('[data-collaborate-presence]'));
        self::assertCount(0, $page->filter('form[data-collaborate]'));
        self::assertCount(1, $page->filter('form[name="' . $this->formName() . '"]'));
    }

    public function testTheHubSessionAnswersASubscriberTokenForThePagesTopics(): void
    {
        $this->signIn('alice');
        $page = $this->open($this->editUrl($this->article()));
        $layer = self::layer($page);
        $configuration = self::configuration($page);

        $session = $this->post($layer['session'], $layer['csrf'], [
            'grant' => $layer['grant'],
            'updates' => true,
            'drafts' => [$configuration['grant'], 'forged.grant'],
        ]);

        self::assertSame('http://127.0.0.1/.well-known/mercure', $session['url']);
        self::assertSame(15, $session['userId']);
        self::assertSame([], $session['resend']);
        self::assertSame([1], $session['denied']);
        $topics = $session['topics'];
        self::assertIsArray($topics);
        self::assertCount(3, $topics);
        self::assertStringStartsWith('urn:collaborate:cursors:', (string) $topics[0]);
        self::assertSame('urn:collaborate:updates', $topics[1]);
        self::assertStringStartsWith('urn:collaborate:draft:', (string) $topics[2]);
        // The token grants subscribing to exactly those topics.
        self::assertIsString($session['token']);
        $claims = self::claims($session['token']);
        self::assertSame('collaborate-' . static::kernel()::app(), $claims['iss'] ?? null);
        self::assertStringContainsString(
            json_encode($topics[2], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
        self::assertArrayHasKey('exp', $claims);

        // Without the page's CSRF token, or the hub's: refused.
        $this->post($layer['session'], 'wrong', ['grant' => $layer['grant']], 403);
        $this->post($layer['session'], $configuration['csrf'], ['grant' => $layer['grant']], 403);
    }

    public function testCursorEventsArePublishedToThePageWithTheSendersIdentity(): void
    {
        $this->signIn('alice');
        $layer = self::layer($this->open($this->editUrl($this->article())));
        $event = [
            'grant' => $layer['grant'],
            'clientId' => 'client-0123456789abcdef',
            'sequence' => 1,
            'kind' => 'move',
            'style' => 'mac',
            'shape' => 'text',
            'anchor' => ['region' => 'content', 'selector' => 'input[name="title"]', 'x' => 0.5, 'y' => 0.5],
            'caret' => null,
            'field' => $this->formName() . '[title]',
            // Whatever the client claims about its sender is replaced.
            'label' => 'Mallory',
            'userId' => 99,
        ];
        $this->post((string) $layer['events'], $layer['csrf'], $event, 204);

        $room = static fn(Update $update): string => (string) ($update->getTopics()[0] ?? '');
        $cursors = array_values(array_filter($this->published(), static fn(Update $update): bool => str_starts_with(
            $room($update),
            'urn:collaborate:cursors:',
        )));
        self::assertCount(1, $cursors);
        [$published] = self::on($cursors, $room($cursors[0]));
        self::assertSame('cursors', $published['channel']);
        self::assertSame(15, $published['userId']);
        self::assertSame('Alice', $published['label']);
        self::assertSame('move', $published['kind']);
        self::assertSame($this->formName() . '[title]', $published['field']);

        // A move without position, a forged grant or a missing token are refused, and nothing is published.
        $this->post((string) $layer['events'], $layer['csrf'], ['anchor' => null, 'field' => null] + $event, 400);
        $this->post((string) $layer['events'], $layer['csrf'], ['grant' => 'forged'] + $event, 403);
        $this->post((string) $layer['events'], '', $event, 403);
        self::assertSame([], $this->published());
    }

    public function testTwoEditorsShareADraftUntilOneSavesItAndOneDiscardsTheNext(): void
    {
        $id = $this->article('Shared', 'Start');
        $summary = $this->formName() . '[summary]';

        // Alice joins and types.
        $this->signIn('alice');
        $alicePage = $this->open($this->editUrl($id));
        $alice = self::configuration($alicePage);
        $joined = $this->draft($alice, 'join');
        self::assertSame('Start', Arrays::field($joined, $summary)['value']);
        $appended = $this->type($alice, $joined, $summary, 'Start', 'Start by Alice');
        self::assertIsInt($appended['revision']);
        $notices = self::on($this->published(), self::draftTopic($alice));
        self::assertSame('append', $notices[0]['kind'] ?? null);
        self::assertSame($appended['revision'], $notices[0]['revision'] ?? null);

        // Bob opens the same record: his page joins the same draft and replays Alice's text.
        $this->signIn('bob');
        $bobPage = $this->open($this->editUrl($id));
        $bob = self::configuration($bobPage);
        self::assertSame(self::draftTopic($alice), self::draftTopic($bob));
        $bobState = $this->draft($bob, 'join');
        self::assertSame('Start by Alice', Arrays::field($bobState, $summary)['value']);
        $replayed = $this->draft($bob, 'replay', ['since' => 0]);
        self::assertSame($bobState['revision'], $replayed['revision']);
        self::assertNotSame($bobState['savedRevision'], $bobState['revision']);

        // Alice saves through the form, at the revision she synchronized.
        $this->signIn('alice');
        $alicePage = $this->open($this->editUrl($id));
        $aliceState = $this->draft(self::configuration($alicePage), 'join');
        $this->save($alicePage, [$summary => 'Start by Alice'], $aliceState);
        self::assertResponseRedirects();
        $saved = self::on($this->published(), self::draftTopic($alice));
        self::assertContains(['channel' => 'draft', 'draft' => self::draftId($alice), 'kind' => 'saved'], $saved);
        $article = $this->em()->find(Article::class, $id);
        self::assertInstanceOf(Article::class, $article);
        self::assertSame('Start by Alice', $article->summary);

        // Bob's next replay reads the checkpoint: saved, by Alice.
        $this->signIn('bob');
        $replayed = $this->draft($bob, 'replay', ['since' => $bobState['revision']]);
        self::assertSame($replayed['revision'], $replayed['savedRevision']);
        self::assertSame(['userId' => 15, 'label' => 'Alice'], $replayed['savedBy']);

        // Bob types again, then discards: the draft is the saved record again, for everyone.
        $typed = $this->type($bob, $replayed, $summary, 'Start by Alice', 'Start by Alice, then Bob');
        $discarded = $this->draft($bob, 'discard', [
            'generation' => $replayed['generation'],
            'revision' => $typed['revision'],
        ]);
        self::assertSame(
            ['channel' => 'draft', 'draft' => self::draftId($bob), 'kind' => 'discard'],
            self::on($this->published(), self::draftTopic($bob))[0],
        );
        self::assertIsInt($discarded['generation']);
        $this->signIn('alice');
        $rejoined = $this->draft(self::configuration($this->open($this->editUrl($id))), 'join');
        self::assertSame($discarded['generation'], $rejoined['generation']);
        self::assertSame('Start by Alice', Arrays::field($rejoined, $summary)['value']);
        self::assertSame($rejoined['revision'], $rejoined['savedRevision']);
    }

    public function testASaveWithAStaleRevisionReturnsToTheFormWithAWarning(): void
    {
        $id = $this->article('Stale', 'Start');
        $summary = $this->formName() . '[summary]';
        $this->signIn('alice');
        $page = $this->open($this->editUrl($id));
        $configuration = self::configuration($page);
        $state = $this->draft($configuration, 'join');
        $this->type($configuration, $state, $summary, 'Start', 'Start, later');

        // The form posts the revision it joined at: a peer's edit landed since.
        $this->save($page, [$summary => 'Start'], $state);

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The shared draft changed while saving. Review it and save again.');
        $article = $this->em()->find(Article::class, $id);
        self::assertInstanceOf(Article::class, $article);
        self::assertSame('Start', $article->summary);
    }

    public function testANewRecordIsDraftedThroughItsInvitationAndCreatedOnce(): void
    {
        $title = $this->formName() . '[title]';
        $this->signIn('alice');
        $page = $this->open($this->newUrl());
        $alice = self::configuration($page);
        self::assertTrue($alice['isNew']);
        self::assertIsString($alice['invitation']);
        self::assertMatchesRegularExpression('/[?&]draft=[a-f0-9]{32}(&|$)/', $alice['invitation']);
        $action = (string) $page->filter('form[data-collaborate]')->attr('action');
        self::assertStringContainsString('draft=', $action);
        $state = $this->draft($alice, 'join');
        $this->type($alice, $state, $title, '', 'Drafted together');

        // Bob opens the invitation: the same draft.
        $this->signIn('bob');
        $bob = self::configuration($this->open($alice['invitation']));
        self::assertSame(self::draftId($alice), self::draftId($bob));
        self::assertSame('Drafted together', Arrays::field($this->draft($bob, 'join'), $title)['value']);

        // Alice creates the record; the redirect drops the draft key, and the draft names the record it created.
        $this->signIn('alice');
        $page = $this->open($alice['invitation']);
        $state = $this->draft(self::configuration($page), 'join');
        $this->save($page, [$title => 'Drafted together'], $state, true);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('draft=', (string) $this->client->getResponse()->headers->get('Location'));
        $created = $this->em()->getRepository(Article::class)->findOneBy(['title' => 'Drafted together']);
        self::assertInstanceOf(Article::class, $created);

        $this->signIn('bob');
        $joined = $this->draft($bob, 'join');
        self::assertSame((string) $created->id, $joined['recordId']);
        // A draft that created its record takes no more edits.
        $this->type($bob, $joined, $title, 'Drafted together', 'Drafted together again', 409);
    }

    public function testTheFormsActionAnswersItsRefreshedGrantAndTokens(): void
    {
        $this->signIn('alice');
        $url = $this->editUrl($this->article());
        $this->client->request('GET', $url, server: ['HTTP_X_COLLABORATE_REFRESH' => '1']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{configuration: array<string, mixed>, tokens: array<string, string>} $refresh */
        $refresh = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([$this->formName() . '[_token]'], array_keys($refresh['tokens']));
        self::assertIsString($refresh['configuration']['grant'] ?? null);
        self::assertFalse($refresh['configuration']['isNew']);
    }

    public function testThePurgeDeletesIdleDrafts(): void
    {
        $this->signIn('alice');
        $this->draft(self::configuration($this->open($this->editUrl($this->article()))), 'join');
        $connection = $this->em()->getConnection();
        $drafts = (int) $connection->fetchOne('SELECT COUNT(*) FROM collaborate_draft');
        self::assertGreaterThan(0, $drafts);

        self::assertStringContainsString('Purged 0 shared draft(s).', $this->purge());
        $connection->executeStatement('UPDATE collaborate_draft SET updated_at = ?', [
            new \DateTimeImmutable('-100 days')->format('Y-m-d H:i:s.u'),
        ]);
        self::assertStringContainsString(\sprintf('Purged %d shared draft(s).', $drafts), $this->purge());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM collaborate_draft'));
    }

    /** The name of the article form: its controls are `<name>[title]`… */
    abstract protected function formName(): string;

    /** @param array{grant: string, ...} $configuration */
    protected static function draftId(array $configuration): string
    {
        $id = self::scope($configuration['grant'])['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** @param array{grant: string, ...} $configuration */
    protected static function draftTopic(array $configuration): string
    {
        return 'urn:collaborate:draft:' . self::draftId($configuration);
    }

    /** @return array<string, mixed> the signed scope of a field grant */
    private static function scope(string $grant): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) base64_decode(explode('.', $grant)[0], true), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    protected static function schema(string $grant): array
    {
        $fields = self::scope($grant)['fields'] ?? null;
        self::assertIsArray($fields);

        /** @var array<string, mixed> */
        return $fields;
    }

    /** @return array<string, mixed> */
    private static function claims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1] ?? '';

        /** @var array<string, mixed> */
        return json_decode(
            (string) base64_decode(strtr($payload, '-_', '+/'), true),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
    }
}
