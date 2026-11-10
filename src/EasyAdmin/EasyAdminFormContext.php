<?php

declare(strict_types=1);

namespace Janalis\Collaborate\EasyAdmin;

use Janalis\Collaborate\Bridge\EasyAdmin\EasyAdminFormContext as Moved;

// The class of before 0.5, autoloaded by its old name: the moved class under this one too.
trigger_deprecation(
    'janalis/collaborate',
    '0.5',
    'The "%s" class is deprecated, use "%s" instead.',
    __NAMESPACE__ . '\\EasyAdminFormContext',
    Moved::class,
);
class_alias(Moved::class, __NAMESPACE__ . '\\EasyAdminFormContext');
