# Rich text and scripts

The bundle shares plain form controls on its own. Two kinds of page code
need to cooperate with it: the code that creates rich-text editors, and
scripts that write into fields.

## CKEditor fields

The page creates its own CKEditor 5 instances; the bundle provides a
plugin, `Collaborate`, and the draft's configuration for each field.

![A CKEditor field shared by Alice and Bob: Bob's selection of the sentence he just typed, and the mark of the field he is in](/screenshots/rich-text.png)

### 1. Mark the fields

List the form types rendered as rich text in
[`drafts.rich_text.block_prefixes`](./configuration#drafts-rich-text). A
field whose type, or one of its parent types, has one of these block
prefixes stores CKEditor model operations in the draft instead of plain
text:

```php
final class RichTextType extends AbstractType
{
    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'rich_text';
    }
}
```

```yaml
collaborate:
    drafts:
        rich_text:
            block_prefixes: [rich_text]
```

### 2. Create the editors with the session

```js
import { ClassicEditor, Essentials, Paragraph, Bold, Italic } from 'ckeditor5';
import Collaborate from '@janalis/collaborate/ckeditor5/collaborate.js';
import { addRowInitializer, sessionFor } from '@janalis/collaborate/easyadmin.js'; // or collaborate.js

async function createEditor(textarea) {
  const session = sessionFor(textarea);
  const shared = session ? await session.editorConfiguration(textarea) : {};
  try {
    const editor = await ClassicEditor.create(textarea, {
      licenseKey: 'GPL',
      plugins: [Essentials, Paragraph, Bold, Italic, Collaborate],
      toolbar: ['bold', 'italic', '|', 'undo', 'redo'],
      ...shared,
    });
    session?.editorReady(textarea.name);
    return editor;
  } catch (error) {
    session?.editorFailed();
    throw error;
  }
}

for (const textarea of document.querySelectorAll('textarea[data-rich-text]')) createEditor(textarea);
// Rows a peer adds to a collection arrive without editors: build theirs too.
addRowInitializer((row) => {
  for (const textarea of row.querySelectorAll('textarea[data-rich-text]')) createEditor(textarea);
});
```

| Call | What it does |
|---|---|
| `sessionFor(field)` | The draft session of the field's form, or `null` when the form is not shared. The first call creates it. |
| `await session.editorConfiguration(field)` | Waits for the session to join the draft, then returns `{ initialData, collaborate }`: the field's content in the shared draft and what the `Collaborate` plugin needs. Spread it last into the editor configuration. It returns `{}` when the draft could not be joined or the field is not shared: the editor then starts from the textarea, without collaboration. |
| `session.editorReady(field.name)` | Reports the editor as mounted. The form's controls stay inert until every rich-text field of the draft has reported; until then, edits wait to be sent (15 seconds at a time, then the session retries). |
| `session.editorFailed()` | Reports an editor that could not be created. The session stops and the form falls back to ordinary editing: the bar says the shared draft is unavailable, and Save posts the form as usual. |
| `addRowInitializer(fn)` | Runs `fn(row)` on every collection row the draft adds to the page; returns a function that unregisters it. One that throws does not keep the others from running. |

The `Collaborate` plugin does nothing in an editor created without the
session's `collaborate` configuration, so the same editor build serves
shared and unshared forms.

### Editor builds and schemas

Every text and rich-text field of a draft has a schema such as
`ckeditor5-48.2.0:collaborate-2:rich:full`: the CKEditor version, the
bundle's protocol, the field's kind and its build. Operations recorded by
one schema never replay into another.

- The version comes from the `ckeditor5` entry of `importmap.php`, or from
  [`drafts.rich_text.ckeditor_version`](./configuration#drafts-rich-text).
- If your forms use two editor builds (a full one and a reduced one, with
  different plugins and therefore different model schemas), name the
  boolean form option that selects the reduced build in
  `drafts.rich_text.schema_option`. Fields where it is `true` get a `basic`
  schema, the others `full`.

Upgrading CKEditor is a schema change; see
[Schema compatibility](./shared-drafts#schema-compatibility).

### Markdown source mode

A field may let its editors switch between rendered editing and a Markdown
source view of the same content. The bundle shares the mode and keeps typed
source and the editor's model in step, under three conditions:

- The editor's data processor produces the source (CKEditor's Markdown
  plugin, say), and the source is edited in the editor's own source
  element, the textarea it was created on (`editor.sourceElement`).
- The mode is chosen by radio buttons named `<textarea id>_view`, the
  source mode's having the value `markdown`. The bundle shares the checked
  value with peers, and on a peer's switch checks the same radio and
  dispatches `change` on it, so your own handler shows or hides the source.
- Once the editor is ready, attach the source:

  ```js
  editor.plugins.get('Collaborate').attachSource();
  ```

Typed source reaches the editor's model once typing pauses (100 ms), when
the textarea loses focus, or just before a peer's edit is applied, so a
peer's edit of the same passage merges with it as in rendered mode. Source
the editor would serialize differently (`**bo` while typing `**bold**`)
stays as typed. While an image upload is pending, the source radio is
disabled and the textarea read-only. The mode is shared, but it never
counts as an unsaved change.

## Scripts that fill fields

The draft records what a control's `input` and `change` events report.

- **A script that sets a field's value must dispatch `input` (or
  `change`)** on the control afterwards. Without an event, peers never see
  the value, and the next replay of that field restores the earlier one.

  ```js
  urlField.value = pickedUrl;
  urlField.dispatchEvent(new Event('input', { bubbles: true }));
  ```

- **A peer's value arrives differently on text and on other controls.** A
  text field (`text`, `textarea`, `email`, `url`, `tel`, `search`) receives
  peer text with `input` and `change` events, which the bundle ignores for
  its own recording. A choice, checkbox or radio receives it without `input`
  or `change` (EasyAdmin's widgets would take those for the user's edit) and
  fires `collaborate:applied` instead, bubbling from the control. Code that
  derives one field from others listens for it:

  ```js
  form.addEventListener('collaborate:applied', (event) => recomputeFrom(event.target));
  ```

- A script that writes a field *without* any event on purpose, such as
  EasyAdmin's slug widget, is supported for that widget only: the bundle
  follows the form's own `input` and `click` events to pick up its writes.

TomSelect and Tagify controls are rendered through their own APIs when a
peer's value arrives, and a choice a peer picked from an autocomplete shows
under the option label their event carried (up to
[`drafts.label_length`](./configuration#drafts) characters).

## Fields that are never shared

Password, file, hidden, button and unmapped fields, disabled fields, and
fields whose name is in
[`drafts.sensitive_fields`](./configuration#drafts) never enter a draft. A
value typed in one of them stays on that page only, and must be entered
again after a reload.
