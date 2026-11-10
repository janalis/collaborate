# Collaborate

Live collaboration in Drupal's administration, on Claro or Gin: editors of
the same content entity share one draft of its form (text, CKEditor 5 rich
text, choices, multi-value fields, paragraphs), see who else is on a page
and where they are, get listings refreshed when a save elsewhere changes
them, and a bell for notifications.

It is the Drupal module of [janalis/collaborate](https://github.com/janalis/collaborate),
a Symfony bundle the module loads into Drupal's container.

```sh
composer require drupal/collaborate
drush pm:install collaborate
```

Read the [Drupal guide](https://janalis.github.io/collaborate/guide/drupal)
for the requirements (Drupal 11.3+ or 12, a Mercure hub or the bundled
WebSocket server), the configuration and the limits.

## Contributing

The module is developed on GitHub, in the `drupal/` directory of
[janalis/collaborate](https://github.com/janalis/collaborate): this
repository is split from it, and its releases are tagged from there.

Merge requests opened here are welcome: each one is mirrored to a pull
request on GitHub, its commits kept with their authors, and reviewed there.
New commits on the merge request update the pull request; when it is merged
or closed, the merge request is told and closed. Issues opened here are
mirrored as GitHub issues.
