<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Category;

/**
 * The categories an article's autocomplete searches.
 *
 * @extends AbstractCrudController<Category>
 */
final class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
    }
}
