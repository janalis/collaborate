# Getting started

This page wires an admin built with EasyAdmin. A Symfony admin without
EasyAdmin takes the same steps plus one service: see
[Without EasyAdmin](./without-easyadmin). It assumes the
[installation](./installation) is done.

## 1. Turn shared drafts on

Cursors and presence are on by default; shared drafts and the saved-view
refresh that follows them are off:

```yaml
# config/packages/collaborate.yaml
collaborate:
    drafts:
        enabled: true
```

An environment variable works too (`enabled: '%env(bool:COLLABORATE_DRAFTS)%'`).

## 2. Say who your collaborators are

Out of the box, a collaborator is any signed-in user holding `ROLE_ADMIN`
whose user object has a positive integer `id` (a public property or
`getId()`). Their name is the user's string form, or its identifier; what
admits them is their roles. Change the role with
[`role`](./configuration#top-level); provide your own services when your users
have avatars, groups or per-entity permissions
([Identity and access](./identity-and-access)).

## 3. Render the layer and the presence

The layer carries the page's signed grant and settings; the presence group
is where peers' avatars appear. Override EasyAdmin's layout:

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

Both functions render nothing for a visitor who is not a collaborator.
Render the layer once per page; a page without it has no cursors, no
presence, no refresh and no shared draft.

## 4. Load the script

Add the bundle's entry point to the import map and import it from the
admin's own script, **before** anything that wraps `window.fetch` (a
loading indicator, an error reporter): the bundle keeps the `fetch` it finds
when its modules load, so its background requests bypass such wrappers.

```php
// importmap.php
return [
    'admin' => ['path' => './assets/admin.js', 'entrypoint' => true],
    '@janalis/collaborate/easyadmin.js' => ['path' => '@janalis/collaborate/easyadmin.js'],
    '@janalis/collaborate/ckeditor5/collaborate.js' => ['path' => '@janalis/collaborate/ckeditor5/collaborate.js'],
    // ckeditor5, bootstrap/js/src/modal.js, and @microsoft/fetch-event-source with Mercure: see Installation
];
```

```js
// assets/admin.js
import '@janalis/collaborate/easyadmin.js';
```

`easyadmin.js` reads EasyAdmin's markup; a page of no admin imports
`@janalis/collaborate/collaborate.js` instead (see [Bridges](./bridges)).

```php
// your DashboardController
public function configureAssets(): Assets
{
    return Assets::new()->addAssetMapperEntry('admin');
}
```

The entry point starts cursors and presence, the saved-view refresh and the
shared drafts of every `form[data-collaborate]` on the page, each once the
document is parsed. It also exports `sessionFor` and `addRowInitializer`
for your editors ([Rich text and scripts](./rich-text)).

## 5. Add the styles

The bundle ships two Sass partials in `assets/scss/`:

- `_cursors.scss`: pointers, carets, field marks and presence avatars. It
  imports Bootstrap's `scss/functions` and `scss/variables`, and gives each
  name of Bootstrap's `$colors` map its color.
- `_draft.scss`: the state bar and the conflict review.

Put the bundle's `assets/scss` directory
(`vendor/janalis/collaborate/assets/scss`) and the directory holding
`bootstrap/scss` (`node_modules`, for example) on your Sass load path, then:

```scss
// after Bootstrap, in a stylesheet that compiles it…
@import 'bootstrap/scss/bootstrap';
@import 'cursors';
@import 'draft';
```

With EasyAdmin, which ships Bootstrap already compiled, compile a small
stylesheet of its own and add it with `Assets::addCssFile()`:

```scss
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/variables-dark';
@import 'bootstrap/scss/maps';
@import 'bootstrap/scss/mixins';
@import 'cursors';
@import 'draft';
```

The state bar uses Font Awesome icon classes (`fa fa-…`), which EasyAdmin
loads; without Font Awesome, the bar shows its text without icons.

## 6. Try it

Open the same edit page in two browsers signed in as two collaborators.
Each sees the other's avatar in the header and pointer on the page; typing
in a text field appears on the other side, and the bar above the form says
whether the shared draft is saved. A rich-text field needs one more step:
[Rich text and scripts](./rich-text).

## Next steps

- [Configuration](./configuration): every option.
- [Bridges](./bridges): EasyAdmin, Drupal, or none.
- [EasyAdmin](./easyadmin): what its bridge does.
- [Shared drafts](./shared-drafts): what editors see, and the limits.
- [Operations and security](./operations): before going to production.
