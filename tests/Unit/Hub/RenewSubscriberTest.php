<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub;

use Janalis\Collaborate\Hub\RenewSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(RenewSubscriber::class)]
final class RenewSubscriberTest extends TestCase
{
    public function testListensBeforeTheSessionIsSaved(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => 'keep', KernelEvents::RESPONSE => 'restore'],
            RenewSubscriber::getSubscribedEvents(),
        );
        self::assertSame('X-Collaborate-Renew', RenewSubscriber::HEADER);
    }

    public function testARenewalPutsBackTheFlashesItsRenderReadAndKeepsTheOnesItAddedOrLeft(): void
    {
        $session = $this->session(['success' => ['Saved'], 'info' => ['Left']]);
        $request = $this->request($session, true);
        $subscriber = new RenewSubscriber();

        $subscriber->keep($this->requestEvent($request));
        self::assertInstanceOf(\Closure::class, $request->attributes->get('_collaborate_renew'));
        $session->getFlashBag()->get('success');
        $session->getFlashBag()->add('success', 'Added');
        $session->getFlashBag()->add('warning', 'New');
        $subscriber->restore($this->responseEvent($request));

        self::assertSame(
            ['success' => ['Saved', 'Added'], 'info' => ['Left'], 'warning' => ['New']],
            $session->getFlashBag()->peekAll(),
        );
    }

    public function testATypeTheRenderAddedToWithoutReadingKeepsItsMessagesOnce(): void
    {
        $session = $this->session(['success' => ['Saved']]);
        $request = $this->request($session, true);
        $subscriber = new RenewSubscriber();

        $subscriber->keep($this->requestEvent($request));
        $session->getFlashBag()->add('success', 'Added');
        $subscriber->restore($this->responseEvent($request));

        self::assertSame(['success' => ['Saved', 'Added']], $session->getFlashBag()->peekAll());
    }

    public function testOtherRequestsReadTheFlashes(): void
    {
        $subscriber = new RenewSubscriber();
        foreach ([
            [false, HttpKernelInterface::MAIN_REQUEST],
            [true,  HttpKernelInterface::SUB_REQUEST],
        ] as [$renewal, $type]) {
            $session = $this->session(['success' => ['Saved']]);
            $request = $this->request($session, $renewal);
            $subscriber->keep($this->requestEvent($request, $type));
            $session->getFlashBag()->all();
            $subscriber->restore($this->responseEvent($request, $type));

            self::assertSame([], $session->getFlashBag()->peekAll());
        }
    }

    public function testARenewalWithoutASessionStartsNone(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request(server: ['HTTP_X_COLLABORATE_RENEW' => '1']);
        $request->setSession($session);
        $subscriber = new RenewSubscriber();

        $subscriber->keep($this->requestEvent($request));
        $subscriber->restore($this->responseEvent($request));

        self::assertFalse($session->isStarted());
    }

    public function testASessionThatHoldsNoFlashBagIsLeftAlone(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->never())->method('getBag');
        $session->method('getName')->willReturn('SESSION');
        $request = new Request(server: ['HTTP_X_COLLABORATE_RENEW' => '1']);
        $request->setSession($session);
        $request->cookies->set('SESSION', 'id');
        $subscriber = new RenewSubscriber();

        $subscriber->keep($this->requestEvent($request));
        $subscriber->restore($this->responseEvent($request));
        self::assertFalse($request->attributes->has('_collaborate_renew'));
    }

    /** @param array<string, list<string>> $flashes */
    private function session(array $flashes): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->getFlashBag()->setAll($flashes);

        return $session;
    }

    private function request(Session $session, bool $renewal): Request
    {
        $request = new Request(server: $renewal ? ['HTTP_X_COLLABORATE_RENEW' => '1'] : []);
        $request->setSession($session);
        $request->cookies->set($session->getName(), $session->getId());

        return $request;
    }

    private function requestEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    private function responseEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, new Response());
    }
}
