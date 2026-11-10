<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterCrudActionEvent;
use Janalis\Collaborate\Bridge\EasyAdmin\DraftRefreshSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(DraftRefreshSubscriber::class)]
final class DraftRefreshSubscriberTest extends TestCase
{
    private function view(?string $configuration): FormView
    {
        $root = new FormView();
        $root->vars['attr'] = null === $configuration ? [] : ['data-collaborate' => $configuration];
        $name = new FormView($root);
        $name->vars['full_name'] = 'Article[name]';
        $name->vars['value'] = 'Name';
        $token = new FormView($root);
        $token->vars['full_name'] = 'Article[_token]';
        $token->vars['value'] = 'root-token';
        $entry = new FormView($root);
        $nested = new FormView($entry);
        $nested->vars['full_name'] = 'Article[children][0][_token]';
        $nested->vars['value'] = 'entry-token';
        $entry->children = ['_token' => $nested];
        $root->children = ['name' => $name, '_token' => $token, 'children' => $entry];

        return $root;
    }

    private function event(Request $request, mixed $form, string $parameter = 'edit_form'): AfterCrudActionEvent
    {
        $requests = new RequestStack();
        $requests->push($request);
        $event = new AfterCrudActionEvent(null, KeyValueStore::new([$parameter => $form]));
        new DraftRefreshSubscriber($requests)($event);

        return $event;
    }

    private function form(FormView $view): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('createView')->willReturn($view);

        return $form;
    }

    public function testAnswersTheGrantAndTheCsrfTokensInsteadOfThePage(): void
    {
        $request = Request::create('/admin/article/7/edit');
        $request->headers->set('X-Collaborate-Refresh', '1');

        $event = $this->event($request, $this->form($this->view('{"grant":"signed.grant"}')));

        self::assertTrue($event->isPropagationStopped());
        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(
            [
                'configuration' => ['grant' => 'signed.grant'],
                'tokens' => ['Article[_token]' => 'root-token', 'Article[children][0][_token]' => 'entry-token'],
            ],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('X-Collaborate-Refresh', $response->headers->get('Vary'));
    }

    public function testANewRecordFormRenewsItsGrant(): void
    {
        $request = Request::create('/admin/article/new?draft=0123456789abcdef0123456789abcdef');
        $request->headers->set('X-Collaborate-Refresh', '1');

        $event = $this->event($request, $this->form($this->view('{"grant":"renewed"}')), 'new_form');

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        /** @var array{configuration: array<string, mixed>|null} $refresh */
        $refresh = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['grant' => 'renewed'], $refresh['configuration']);
    }

    public function testAFormNoLongerSharedAnswersNoConfiguration(): void
    {
        $request = Request::create('/admin/article/7/edit');
        $request->headers->set('X-Collaborate-Refresh', '1');

        $response = $this->event($request, $this->form($this->view(null)))->getResponse();

        self::assertSame(
            [
                'configuration' => null,
                'tokens' => ['Article[_token]' => 'root-token', 'Article[children][0][_token]' => 'entry-token'],
            ],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    /** @return iterable<string, array{Request, bool}> */
    public static function pagesRendered(): iterable
    {
        yield 'an ordinary page view' => [Request::create('/admin/article/7/edit'), true];
        $post = Request::create('/admin/article/7/edit', 'POST');
        $post->headers->set('X-Collaborate-Refresh', '1');
        yield 'a submission' => [$post, true];
        $index = Request::create('/admin/article');
        $index->headers->set('X-Collaborate-Refresh', '1');
        yield 'an action without an edit form' => [$index, false];
    }

    #[DataProvider('pagesRendered')]
    public function testLeavesEveryOtherResponseToEasyAdmin(Request $request, bool $withForm): void
    {
        $form = $this->createMock(FormInterface::class);
        $form->expects($this->never())->method('createView');

        self::assertFalse($this->event($request, $withForm ? $form : null)->isPropagationStopped());
    }

    public function testDoesNothingOutsideARequest(): void
    {
        $form = $this->createMock(FormInterface::class);
        $form->expects($this->never())->method('createView');
        $event = new AfterCrudActionEvent(null, KeyValueStore::new(['edit_form' => $form]));

        new DraftRefreshSubscriber(new RequestStack())($event);

        self::assertFalse($event->isPropagationStopped());
    }
}
