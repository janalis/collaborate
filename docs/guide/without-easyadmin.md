# Without EasyAdmin

Cursors and presence work on any page that renders the
[layer](./getting-started#_3-render-the-layer-and-the-presence). Shared
drafts work on any Symfony form page once the bundle knows which record the
page edits, and once the page's controller plays its part in Save. This page
lists what EasyAdmin's adapter otherwise does for you.

![The same shared draft on a page rendered by Symfony's Bootstrap 5 form theme, without EasyAdmin](/screenshots/plain.png)

## The form context

Implement `Janalis\Collaborate\Form\FormContextInterface` and alias the
interface to your service:

```php
interface FormContextInterface
{
    /** @return array{entity: object|null, class: class-string, new: bool, id: string|null, draft: string|null}|null */
    public function current(): ?array;

    public function editUrl(): string;
}
```

`current()` describes the record the current page's **root form** edits, or
returns `null` on any other page:

| Key | Value |
|---|---|
| `entity` | The object the root form is built on. A form is shared only when its data is this very object. |
| `class` | The record's class, passed to `DraftAccessCheckerInterface::canEdit()` and part of the draft id. |
| `new` | `true` on a new-record page (permission `create`), `false` on an edit page (`update`). |
| `id` | The record's identifier as a string on an edit page, `null` on a new-record page. |
| `draft` | The `draft` query parameter the page was opened with, unchecked, or `null`. A new-record page shares the draft it names when it is 32 hex characters, and draws a new one otherwise. |

`editUrl()` returns the URL of the edit page of a record of the current
class, with `__record__` standing for its id. Pages whose draft created its
record link to it with this URL.

A context for an application whose controllers put the edited record on
the request:

```php
namespace App\Collaboration;

use App\Entity\Article;
use Janalis\Collaborate\Form\FormContextInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ArticleFormContext implements FormContextInterface
{
    public const string RECORD = '_app_record';

    public function __construct(
        private RequestStack $requests,
        private UrlGeneratorInterface $urls,
    ) {}

    public function current(): ?array
    {
        $request = $this->requests->getMainRequest();
        $record = $request?->attributes->get(self::RECORD);
        if (null === $request || !\is_array($record) || !($record[0] ?? null) instanceof Article) {
            return null;
        }
        [$article, $new] = $record;
        $draft = $request->query->get('draft');

        return [
            'entity' => $article,
            'class' => Article::class,
            'new' => $new,
            'id' => $new ? null : (string) $article->id,
            'draft' => \is_string($draft) ? $draft : null,
        ];
    }

    public function editUrl(): string
    {
        return $this->urls->generate('article_edit', ['id' => '__record__']);
    }
}
```

```yaml
# config/services.yaml
services:
    Janalis\Collaborate\Form\FormContextInterface: '@App\Collaboration\ArticleFormContext'
```

Several entities can share one context: return whichever record the page
edits.

## The controller

Three things the EasyAdmin adapter does on its own fall to your controller.

### Tell the save it has persisted

After a successful flush, call `DraftSave::completed()` with the record.
The bundle then records the saved values in the draft when the response is
ready (and, on a new-record page, which record the draft created):

```php
use Janalis\Collaborate\Draft\DraftSave;

public function edit(Request $request, Article $article, DraftSave $save): Response
{
    $request->attributes->set(ArticleFormContext::RECORD, [$article, false]);
    $form = $this->createForm(ArticleType::class, $article);
    // … refresh requests, below
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
        $this->em->flush();
        $save->completed($article);

        return $this->redirectToRoute('article_edit', ['id' => $article->id], Response::HTTP_SEE_OTHER);
    }

    return $this->render('article/edit.html.twig', ['form' => $form], new Response(
        status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
    ));
}
```

Without the call, the record is saved but the draft keeps its old
baseline, and editors then see the save as a difference with the saved
record.

### Refresh requests

Before a Save, and when a field grant must be renewed, the page asks the
URL its form posts to with `X-Collaborate-Refresh: 1` (a `GET`). Answer
with the form's fresh configuration and CSRF tokens as JSON instead of
rendering the page:

```php
if ($request->isMethodSafe() && '1' === $request->headers->get('X-Collaborate-Refresh')) {
    $view = $form->createView();
    $configuration = $view->vars['attr']['data-collaborate'] ?? null;

    return new JsonResponse([
        'configuration' => \is_string($configuration) ? json_decode($configuration, true, flags: \JSON_THROW_ON_ERROR) : null,
        'tokens' => $this->csrfTokens($view),    // ['article[_token]' => '…', …]: each `_token` child by its full name
    ], headers: ['Cache-Control' => 'no-store', 'Vary' => 'X-Collaborate-Refresh']);
}
```

`tokens` maps the full name of every `_token` child of the form view to
its value. A response without `configuration` (a sign-in page, a form no
longer shared) ends the page's session as access expired.

### Errors and flash messages

Render an invalid form again as usual: the shared draft and the submitted
values are kept, and the re-rendered form compares the draft with the
record as it was before the submission.

A Save the bundle cannot hold (a peer's edit landed meanwhile, another Save
holds the draft) is answered with a `303` redirect back to the form and a
`warning` flash message. Display flash messages on your form pages.

## Form themes

Render the form with any stock Symfony form theme: `form_div_layout`,
`form_table_layout`, Bootstrap 3, 4 and 5 (horizontal included),
Foundation 5 and 6, Tailwind 2 or daisyUI 5. Plain <code v-pre>{{ form(form) }}</code> or
`form_row()` calls work: peers' field marks outline a field's row, label
included, and collections stay in sync, with no markup of your own.

The bundle marks a shared form's markup through the attributes every theme
renders:

- each field's row (`row_attr`) carries `data-collaborate-row`;
- each shared collection's row carries `data-collaborate-collection`, its
  widget (`attr`, beside Symfony's `data-prototype`) carries
  `data-collaborate-entries` and `data-collaborate-placeholder`, and each
  entry's row, the prototype's included, carries `data-collaborate-entry`.

A form theme of your own keeps working as long as its `form_row` renders
`row_attr`, its collection widget renders `attr`, and entries are rendered
with `form_row`, which is what Symfony's own blocks do. Templates that
follow EasyAdmin's markup (a `.form-group` row, `.field-collection-item`
entries in `.ea-form-collection-items`, the prototype on the collection's
row) are still read.

Your own add and remove buttons only need to add or remove entries in the
collection's widget: the bundle observes them. Build an added entry from the
widget's `data-prototype`, replacing its `data-collaborate-placeholder`
(`__name__` by default) with a new key; with `form_table_layout`, the
prototype is a table row, so parse it inside a `<table><tbody>`. Rows a peer
adds are built from the prototype; register the widgets they need with
[`addRowInitializer()`](./rich-text#_2-create-the-editors-with-the-session).

The state bar and its confirmations are Bootstrap components, whatever the
form theme: load Bootstrap's CSS and provide `bootstrap/js/src/modal.js`
(see [Installation](./installation)).

## What you do not get

- **Saved-view refresh** reloads EasyAdmin index and detail pages only (it
  reads EasyAdmin's `<body>` classes). Your own lists stay as they are. The
  notices to open edit pages, which compare their draft with a record saved
  elsewhere, work everywhere, for writes on paths matched by
  [`updates.path_pattern`](./configuration#updates) or named in
  `updates.routes`.
- The state bar's icons are Font Awesome classes; load Font Awesome or the
  bar shows text only.
