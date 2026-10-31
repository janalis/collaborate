<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGenerator $urls,
    ) {}

    public function index(): Response
    {
        return new RedirectResponse($this->urls->setController(ArticleCrudController::class)->generateUrl());
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkTo(ArticleCrudController::class, 'Articles', 'fa fa-file');
        yield MenuItem::linkTo(TagCrudController::class, 'Tags', 'fa fa-tag');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Categories', 'fa fa-folder');
    }

    /** The bundle's styles and the page script that starts it and the rich-text editors. */
    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('build/collaborate.css')
            ->addCssFile('vendor/ckeditor5/ckeditor5.css')
            ->addAssetMapperEntry('admin');
    }
}
