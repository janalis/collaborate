<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional;

use Janalis\Collaborate\Tests\Fixtures\TestKernel;
use Janalis\Collaborate\Tests\Fixtures\TestUserProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Boots {@see TestKernel}; `$collaborate` is the bundle configuration of the next kernel a test creates. */
abstract class CollaborateWebTestCase extends WebTestCase
{
    /** @var array<string, mixed> */
    protected static array $collaborate = [];

    #[\Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /** @param array<array-key, mixed> $options */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel(
            \is_string($options['environment'] ?? null) ? $options['environment'] : 'test',
            \is_bool($options['debug'] ?? null) ? $options['debug'] : true,
            static::$collaborate,
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        static::$collaborate = [];
    }

    protected function signIn(string $username = 'alice'): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser(new TestUserProvider()->loadUserByIdentifier($username));

        return $client;
    }
}
