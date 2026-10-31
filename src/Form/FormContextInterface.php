<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Form;

/**
 * Which record the current page's root form edits. An admin adapter implements it (the bundle's EasyAdmin
 * one is {@see \Janalis\Collaborate\EasyAdmin\EasyAdminFormContext}); without one, forms share no draft.
 *
 * @phpstan-type FormRecord array{entity: object|null, class: class-string, new: bool, id: string|null, draft: string|null}
 */
interface FormContextInterface
{
    /**
     * The edited record when the current page is an edit or new form, null on any other page: the object
     * the root form is built on, its class, whether the form creates it, its id (an edit form's), and the
     * `draft` query parameter a new-record page was opened with (unchecked).
     *
     * @return FormRecord|null
     */
    public function current(): ?array;

    /** The URL of the edit page of a record of the current entity, `__record__` standing for its id. */
    public function editUrl(): string;
}
