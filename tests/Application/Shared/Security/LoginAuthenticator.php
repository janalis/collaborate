<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/** Signs in the account named in the login form: the test applications' accounts have no password. */
final class LoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(new UserBadge($request->request->getString('username')));
    }

    public function onAuthenticationSuccess(
        Request $request,
        #[\SensitiveParameter]
        TokenInterface $token,
        string $firewallName,
    ): ?Response {
        $session = $request->getSession();

        return new RedirectResponse(
            $this->getTargetPath($session, $firewallName) ?? '/admin',
            Response::HTTP_SEE_OTHER,
        );
    }

    protected function getLoginUrl(Request $request): string
    {
        return '/login';
    }
}
