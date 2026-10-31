<?php

declare(strict_types=1);

namespace Janalis\Collaborate\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Janalis\Collaborate\Form\FormContextInterface;

/** The record of an EasyAdmin edit or new page. */
final readonly class EasyAdminFormContext implements FormContextInterface
{
    public function __construct(
        private AdminContextProviderInterface $contexts,
        private AdminUrlGeneratorInterface $adminUrls,
    ) {}

    public function current(): ?array
    {
        $context = $this->contexts->getContext();
        $page = $context?->getCrud()?->getCurrentPage();
        if (null === $context || !\in_array($page, [Crud::PAGE_EDIT, Crud::PAGE_NEW], true)) {
            return null;
        }
        $entity = $context->getEntity();
        $new = Crud::PAGE_NEW === $page;
        $draft = $context->getRequest()->query->get('draft');

        return [
            'entity' => $entity->getInstance(),
            'class' => $entity->getFqcn(),
            'new' => $new,
            'id' => $new ? null : $entity->getPrimaryKeyValueAsString(),
            'draft' => \is_string($draft) ? $draft : null,
        ];
    }

    public function editUrl(): string
    {
        return (clone $this->adminUrls)
            ->unset('draft')
            ->setAction(Action::EDIT)
            ->setEntityId('__record__')
            ->generateUrl();
    }
}
