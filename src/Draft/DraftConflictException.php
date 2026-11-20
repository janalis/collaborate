<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The draft moved past what the page holds, or the record past the draft: the page synchronizes, then retries. */
class DraftConflictException extends ConflictHttpException implements DraftExceptionInterface {}
