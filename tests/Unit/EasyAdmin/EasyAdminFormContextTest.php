<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\EasyAdmin;

use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Context\AdminContextInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\CrudDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Janalis\Collaborate\EasyAdmin\EasyAdminFormContext;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use Janalis\Collaborate\Tests\Fixtures\Draft\RecordingUrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(EasyAdminFormContext::class)]
final class EasyAdminFormContextTest extends TestCase
{
    private function context(?string $page, Request $request, ?Article $instance = null): EasyAdminFormContext
    {
        $crud = new CrudDto();
        if (null !== $page) {
            $crud->setPageName($page);
        }
        $metadata = new ClassMetadata(Article::class);
        $metadata->setIdentifier(['id']);
        $entity = new EntityDto(Article::class, $metadata, null, $instance);
        $context = $this->createStub(AdminContextInterface::class);
        $context->method('getEntity')->willReturn($entity);
        $context->method('getCrud')->willReturn($crud);
        $context->method('getRequest')->willReturn($request);
        $contexts = $this->createStub(AdminContextProviderInterface::class);
        $contexts->method('getContext')->willReturn($context);

        return new EasyAdminFormContext($contexts, $this->createStub(AdminUrlGeneratorInterface::class));
    }

    public function testAnEditPageEditsItsRecord(): void
    {
        $article = new Article();
        $article->id = 7;

        self::assertSame(
            ['entity' => $article, 'class' => Article::class, 'new' => false, 'id' => '7', 'draft' => null],
            $this->context(Crud::PAGE_EDIT, Request::create('/admin/article/7/edit'), $article)->current(),
        );
    }

    public function testANewPageNamesTheDraftKeyItWasOpenedWith(): void
    {
        $article = new Article();

        self::assertSame(
            ['entity' => $article, 'class' => Article::class, 'new' => true, 'id' => null, 'draft' => 'key'],
            $this->context(Crud::PAGE_NEW, Request::create('/admin/article/new?draft=key'), $article)->current(),
        );
        self::assertNull(
            $this->context(Crud::PAGE_NEW, Request::create('/admin/article/new'))->current()['draft'] ?? null,
        );
    }

    public function testOtherPagesEditNoRecord(): void
    {
        self::assertNull($this->context(Crud::PAGE_INDEX, Request::create('/admin/article'))->current());
        self::assertNull($this->context(null, Request::create('/admin'))->current());
        $contexts = $this->createStub(AdminContextProviderInterface::class);
        $contexts->method('getContext')->willReturn(null);
        self::assertNull(
            new EasyAdminFormContext($contexts, $this->createStub(AdminUrlGeneratorInterface::class))->current(),
        );
        // A dashboard page runs in an admin context without a CRUD.
        $dashboard = $this->createStub(AdminContextInterface::class);
        $dashboard->method('getCrud')->willReturn(null);
        $contexts = $this->createStub(AdminContextProviderInterface::class);
        $contexts->method('getContext')->willReturn($dashboard);
        self::assertNull(
            new EasyAdminFormContext($contexts, $this->createStub(AdminUrlGeneratorInterface::class))->current(),
        );
    }

    public function testTheEditUrlLeavesTheSharedGeneratorUntouched(): void
    {
        $urls = new RecordingUrlGenerator(['crudAction' => 'new', 'draft' => 'key', 'menuIndex' => '2']);
        $contexts = $this->createStub(AdminContextProviderInterface::class);

        $editUrl = new EasyAdminFormContext($contexts, $urls)->editUrl();

        self::assertSame('/admin?crudAction=edit&menuIndex=2&entityId=__record__', $editUrl);
        self::assertSame(['crudAction' => 'new', 'draft' => 'key', 'menuIndex' => '2'], $urls->parameters);
    }
}
