<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A second creation from the draft of a new record: the first one saved it already. */
final class RecordAlreadyCreatedException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct(DraftJournal::ALREADY_CREATED);
    }
}
