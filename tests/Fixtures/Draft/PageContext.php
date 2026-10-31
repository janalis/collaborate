<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Janalis\Collaborate\Form\FormContextInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/** The page a form test renders: an edit or new form of `$entity`, or (`$page` null) any other page. */
final class PageContext implements FormContextInterface
{
    public const string EDIT = 'edit';
    public const string NEW = 'new';

    public function __construct(
        private readonly RequestStack $requests,
        public ?object $entity = null,
        public ?string $page = self::NEW,
        public string $id = '7',
    ) {}

    public function current(): ?array
    {
        if (null === $this->page || null === $this->entity) {
            return null;
        }
        $new = self::NEW === $this->page;
        $draft = $this->requests->getMainRequest()?->query->get('draft');

        return [
            'entity' => $this->entity,
            'class' => $this->entity::class,
            'new' => $new,
            'id' => $new ? null : $this->id,
            'draft' => \is_string($draft) ? $draft : null,
        ];
    }

    public function editUrl(): string
    {
        return '/admin/article/__record__/edit';
    }
}
