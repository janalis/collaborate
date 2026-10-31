<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A host's rich-text field: its block prefix is configured in `drafts.rich_text.block_prefixes`. */
final class RichTextType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('compact', false)->setAllowedTypes('compact', 'bool');
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'rich_text';
    }
}
