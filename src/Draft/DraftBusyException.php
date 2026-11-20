<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Another process held the draft's lock past the wait, a save most often: the page retries a second later. */
final class DraftBusyException extends ServiceUnavailableHttpException implements DraftExceptionInterface
{
    public function __construct()
    {
        parent::__construct(1, 'Collaborate draft is busy.');
    }
}
