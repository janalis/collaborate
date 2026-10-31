# EasyAdmin

When EasyAdminBundle (4.24 or later, or 5) is **registered** in the kernel,
the bundle loads its EasyAdmin adapter. Having EasyAdmin installed as a
dependency of another package is not enough.

## What the adapter does

| Service | Role |
|---|---|
| `EasyAdminFormContext` | The [form context](./without-easyadmin#the-form-context): on a CRUD `edit` or `new` page, the edited entity instance, its class, its id and the page's `draft` query parameter; the edit URL of the current CRUD controller with `__record__` for the id. |
| `DraftRefreshSubscriber` | Answers an edit or new action asked with `X-Collaborate-Refresh: 1` with the form's fresh `data-collaborate` configuration and CSRF tokens as JSON, instead of rendering the page. Pages use it to renew a field grant and to compare the draft with a record saved elsewhere before Save. |
| `SaveSubscriber` | Tells the locked save that EasyAdmin has persisted the record (`AfterEntityPersistedEvent`, `AfterEntityUpdatedEvent`), so the draft records the saved values once the response is ready. |

With them:

- **Edit and new forms share drafts.** The root form of a CRUD edit page
  shares the draft of its record; a new page shares the draft of its own
  new record, which its invitation link opens on other pages.
- **Save goes through EasyAdmin.** Validation, `persistEntity()` /
  `updateEntity()`, events and redirects stay as they are. The bundle holds
  the draft's lock from its revision check to the response and records the
  checkpoint after the flush; see [Saving](./shared-drafts#saving).
- **Index and detail pages refresh** after a save elsewhere touches their
  entity ([Saved-view refresh](./saved-views)). EasyAdmin names the page's
  entity in the `<body>` class (`ea-index-Article`), which the refresh
  compares with the entities a notice names.
- **Collections, autocompletes and slugs.** Rows of `CollectionField`
  forms keep stable keys across pages; rows a peer adds are built from the
  collection's prototype and announced with EasyAdmin's
  `ea.collection.item-added` event, so its own row behaviors attach. Saved
  autocomplete values follow EasyAdmin's association mapper, and the slug
  widget's writes reach peers.
- **The dirty-form prompt.** A page that reloads on its own (after a
  Discard, a compaction, or when the editor chose the saved record) skips
  EasyAdmin's "unsaved changes" prompt: the draft holds what the reload
  keeps.

## Layout

Render the presence group and the layer by overriding EasyAdmin's layout:

```twig
{# templates/bundles/EasyAdminBundle/layout.html.twig #}
{% extends '@!EasyAdmin/layout.html.twig' %}

{% block header_custom_menu_wrapper %}
    {{ collaborate_presence() }}
    {{ parent() }}
{% endblock %}

{% block configured_body_contents %}
    {{ parent() }}
    {{ collaborate_layer() }}
{% endblock %}
```

and load the script and styles from the dashboard:

```php
public function configureAssets(): Assets
{
    return Assets::new()
        ->addCssFile('styles/collaborate.css')   // compiled from the bundle's partials
        ->addAssetMapperEntry('admin');           // imports '@janalis/collaborate/easyadmin.js' first
}
```

See [Getting started](./getting-started) for the import map and the Sass
partials.

## Rich-text fields

Give the field a form type whose block prefix is listed in
[`drafts.rich_text.block_prefixes`](./configuration#drafts-rich-text), and
create its editor as described in [Rich text and scripts](./rich-text):

```php
public function configureFields(string $pageName): iterable
{
    yield TextField::new('title');
    yield TextareaField::new('content')->setFormType(RichTextType::class);
    yield CollectionField::new('tags')->setEntryType(TagType::class)->setFormTypeOption('by_reference', false);
}
```

## Translatable fields

Gedmo translatable properties get one draft state per locale, with or
without EasyAdmin; see [Translated fields](./shared-drafts#translated-fields).
