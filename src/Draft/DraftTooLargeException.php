<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** An update, or the draft it would grow, past the `collaborate.drafts` limits: 413 Content Too Large. */
final class DraftTooLargeException extends HttpException implements DraftExceptionInterface
{
    public function __construct(string $message)
    {
        parent::__construct(413, $message);
    }
}
