<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Form;

use Janalis\Collaborate\Tests\Application\Shared\Entity\Tag;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A tag entry; its `position` child is the order the shared draft keeps, its note a rich-text field per entry. */
final class TagType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', options: ['label' => 'Tag', 'empty_data' => ''])->add('position', HiddenType::class, [
            'empty_data' => '0',
        ])->add('note', RichTextType::class, ['label' => 'Note', 'empty_data' => '', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Tag::class, 'empty_data' => static fn(): Tag => new Tag()]);
    }
}
