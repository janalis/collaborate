<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Form\RichTextType;
use Janalis\Collaborate\Tests\Application\Shared\Form\TagType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

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
        yield TextareaField::new('notes')
            ->setFormType(RichTextType::class)
            ->setFormTypeOptions(['markdown' => true, 'empty_data' => ''])
            ->setRequired(false)
            ->hideOnIndex();
        yield ChoiceField::new('status')->setChoices(Article::STATUSES);
        yield ChoiceField::new('audience')->setChoices(Article::AUDIENCES)->renderExpanded()->hideOnIndex();
        yield BooleanField::new('featured')->renderAsSwitch(false);
        yield IntegerField::new('rating')->setRequired(false)->hideOnIndex();
        yield DateField::new('publishedOn')
            ->setFormTypeOption('input', 'datetime_immutable')
            ->setRequired(false)
            ->hideOnIndex();
        yield AssociationField::new('category')->autocomplete()->setRequired(false)->hideOnIndex();
        // Never shared: an unmapped password stays on the page that typed it.
        yield TextField::new('previewPassword')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('mapped', false)
            ->setRequired(false)
            ->onlyOnForms();
        yield CollectionField::new('tags')
            ->setEntryType(TagType::class)
            ->setEntryIsComplex()
            ->renderExpanded()
            ->setFormTypeOption('by_reference', false)
            ->hideOnIndex();
    }
}
