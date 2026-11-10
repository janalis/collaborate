import { drush, expect, test } from './lib/drupal.js';

// Inline Entity Form's simple widget: each referenced page's form is an item's subform, shared as the others.
test.describe("a dossier's inline pages edited by two editors", () => {
  test('carries an edit of a page, and a page added inline, and saves both', async ({ editor }) => {
    const id = Number(
      drush(`$page = \\Drupal\\node\\Entity\\Node::create(['type' => 'page', 'title' => 'Aside']); $page->save();
        $n = \\Drupal\\node\\Entity\\Node::create(['type' => 'dossier', 'title' => 'Related', 'field_related' => [$page]]);
        $n->save(); echo $n->id();`)
    );
    const alice = await editor('alice');
    const bob = await editor('bob');
    await alice.edit(id);
    await bob.edit(id);
    const title = (page, key) =>
      page.form.locator(`[name="field_related[${key}][inline_entity_form][title][0][value]"]`);
    await title(alice, 'p0').fill('Aside by Alice');
    await expect(title(bob, 'p0')).toHaveValue('Aside by Alice');
    await alice.page.locator('[name="field_related_add_more"]').click();
    await title(alice, 't1').fill('New page');
    const rows = bob.form.locator('[data-collaborate-collection="field_related"] tr.draggable');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(1).locator('input[name$="[inline_entity_form][title][0][value]"]')).toHaveValue('New page');
    await bob.save(id);
    await expect
      .poll(() =>
        JSON.parse(
          drush(`\\Drupal::entityTypeManager()->getStorage('node')->resetCache();
            echo json_encode(array_map(static fn($page) => $page->label(), \\Drupal\\node\\Entity\\Node::load(${id})->get('field_related')->referencedEntities()));`)
        )
      )
      .toEqual(['Aside by Alice', 'New page']);
  });
});
