<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** An event no page of this form sends: an unknown field or kind, a malformed operation or value. */
final class InvalidDraftEventException extends BadRequestHttpException implements DraftExceptionInterface {}
