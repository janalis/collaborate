<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Form;

use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Category;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The article form of the plain application. */
final class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['empty_data' => ''])
            ->add('summary', TextareaType::class, ['empty_data' => '', 'required' => false])
            ->add('content', RichTextType::class, ['empty_data' => '', 'required' => false])
            ->add('notes', RichTextType::class, ['empty_data' => '', 'required' => false, 'markdown' => true])
            ->add('status', ChoiceType::class, ['choices' => Article::STATUSES])
            ->add('audience', ChoiceType::class, ['choices' => Article::AUDIENCES, 'expanded' => true])
            ->add('featured', CheckboxType::class, ['required' => false])
            ->add('rating', IntegerType::class, ['required' => false])
            ->add('publishedOn', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'required' => false,
                'placeholder' => 'None',
            ])
            // Never shared: an unmapped password stays on the page that typed it.
            ->add('previewPassword', PasswordType::class, ['mapped' => false, 'required' => false])
            ->add('tags', CollectionType::class, [
                'entry_type' => TagType::class,
                // The entry's own buttons, in its help: every stock theme renders it in the entry's row.
                'entry_options' => [
                    'label' => false,
                    'help' =>
                        '<button type="button" class="btn btn-outline-secondary btn-sm" data-move-up>Move up</button> '
                            . '<button type="button" class="btn btn-outline-danger btn-sm" data-remove-tag>Remove</button>',
                    'help_html' => true,
                ],
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
