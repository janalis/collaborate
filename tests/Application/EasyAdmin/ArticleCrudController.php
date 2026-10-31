<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Form\RichTextType;
use Janalis\Collaborate\Tests\Application\Shared\Form\TagType;

/** @extends AbstractCrudController<Article> */
final class ArticleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Article::class;
    }

    /** The latest articles first: each browser scenario creates its own. */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title');
        yield TextareaField::new('summary')->setRequired(false)->setFormTypeOption('empty_data', '')->hideOnIndex();
        // Not required: CKEditor hides the textarea, which the browser would refuse to submit empty.
        yield TextareaField::new('content')
            ->setFormType(RichTextType::class)
            ->setRequired(false)
            ->setFormTypeOption('empty_data', '')
            ->hideOnIndex();
        yield ChoiceField::new('status')->setChoices(Article::STATUSES);
        yield CollectionField::new('tags')
            ->setEntryType(TagType::class)
            ->setFormTypeOption('by_reference', false)
            ->hideOnIndex();
    }
}
