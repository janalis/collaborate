<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/**
 * A refusal of the draft journal or its save. Each one is also the Symfony HTTP exception the journal threw before
 * it had its own, with the same status: a host that catches `ConflictHttpException` still catches a conflict.
 */
interface DraftExceptionInterface extends \Throwable {}
