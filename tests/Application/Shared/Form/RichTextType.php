<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/** A textarea the page script turns into a CKEditor; listed in `drafts.rich_text.block_prefixes`. */
final class RichTextType extends AbstractType
{
    /** The marker assets/editors.js looks for; the textarea renders as its parent type's widget. */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $view->vars['attr'];
        $view->vars['attr'] = ['data-rich-text' => 'true'] + $attributes;
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
