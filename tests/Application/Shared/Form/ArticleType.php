<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Form;

use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The article form of the plain application. */
final class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class, ['empty_data' => ''])->add('summary', TextareaType::class, [
            'empty_data' => '',
            'required' => false,
        ])->add('content', RichTextType::class, [
            'empty_data' => '',
            'required' => false,
        ])->add('status', ChoiceType::class, ['choices' => Article::STATUSES])->add('tags', CollectionType::class, [
            'entry_type' => TagType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'prototype' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', Article::class);
    }
}
