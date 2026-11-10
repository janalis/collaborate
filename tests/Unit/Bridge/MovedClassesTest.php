<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Bridge;

use Janalis\Collaborate\Bridge\EasyAdmin\DraftRefreshSubscriber;
use Janalis\Collaborate\Bridge\EasyAdmin\EasyAdminFormContext;
use Janalis\Collaborate\Bridge\EasyAdmin\SaveSubscriber;
use Janalis\Collaborate\Updates\UpdatesSubscriber;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** The names of before 0.5 still load their class, with a deprecation (src/EasyAdmin, out of the coverage). */
#[CoversNothing]
final class MovedClassesTest extends TestCase
{
    /** @return iterable<string, array{string, class-string}> */
    public static function moves(): iterable
    {
        yield 'form context' => ['EasyAdminFormContext', EasyAdminFormContext::class];
        yield 'save subscriber' => ['SaveSubscriber', SaveSubscriber::class];
        yield 'refresh subscriber' => ['DraftRefreshSubscriber', DraftRefreshSubscriber::class];
        yield 'updates subscriber' => ['UpdatesSubscriber', UpdatesSubscriber::class];
    }

    /** @param class-string $moved */
    #[DataProvider('moves')]
    #[IgnoreDeprecations]
    #[RunInSeparateProcess]
    public function testTheOldNameIsADeprecatedAliasOfTheMovedClass(string $name, string $moved): void
    {
        $old = 'Janalis\Collaborate\EasyAdmin\\' . $name;
        $this->expectUserDeprecationMessage(\sprintf(
            'Since janalis/collaborate 0.5: The "%s" class is deprecated, use "%s" instead.',
            $old,
            $moved,
        ));

        self::assertTrue(class_exists($old));
        self::assertSame($moved, new \ReflectionClass($old)->getName());
    }
}
