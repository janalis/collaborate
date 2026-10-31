<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A textarea the page script turns into a CKEditor; listed in `drafts.rich_text.block_prefixes`. With
 * `markdown`, the editor stores Markdown and offers a source view of it.
 */
final class RichTextType extends AbstractType
{
    /** The markers assets/editors.js looks for; the textarea renders as its parent type's widget. */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $view->vars['attr'];
        $markers = ['data-rich-text' => 'true'];
        if (true === $options['markdown']) {
            $markers['data-markdown'] = 'true';
        }
        $view->vars['attr'] = $markers + $attributes;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('markdown', false)->setAllowedTypes('markdown', 'bool');
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
