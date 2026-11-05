# Identity and access

Two services decide who collaborates and on what. Both have defaults; an
application with avatars, groups or per-entity permissions provides its
own.

## Who a user is

`Janalis\Collaborate\Identity\CollaboratorProviderInterface`:

```php
interface CollaboratorProviderInterface
{
    public function id(UserInterface $user): ?int;
    public function label(UserInterface $user): string;
    public function avatarUrl(UserInterface $user): ?string;
    /** @return list<string> */
    public function authorization(UserInterface $user): array;
}
```

| Method | Returns |
|---|---|
| `id()` | A stable positive integer, or `null` when the user cannot collaborate (not persisted yet, say). It also picks the user's color (`colors[id % count]`). |
| `label()` | Plain text shown on the user's cursor, presence tooltip and draft notices ("All changes saved by …"). |
| `avatarUrl()` | A same-origin image URL for the header avatars, or `null` for the user's initial on their color. Browsers drop a URL from another origin. |
| `authorization()` | Everything that admits the user: roles, group roles, per-entity permissions… Grants carry a hash of it, so a grant issued before any of it changed is refused. |

The default, `janalis_collaborate.identity.security`
(`SecurityCollaboratorProvider`), reads a public `id` property or a
`getId()` method (an integer, or a string of digits), labels the user by
its string form (its user identifier when it has none), has no avatar and
returns the user's roles.

```php
namespace App\Collaboration;

use App\Entity\User;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class CollaboratorProvider implements CollaboratorProviderInterface
{
    public function id(UserInterface $user): ?int
    {
        return $user instanceof User ? $user->id : null;
    }

    public function label(UserInterface $user): string
    {
        return $user instanceof User ? $user->displayName : $user->getUserIdentifier();
    }

    public function avatarUrl(UserInterface $user): ?string
    {
        return $user instanceof User && null !== $user->avatar ? '/avatars/' . $user->avatar : null;
    }

    public function authorization(UserInterface $user): array
    {
        $roles = $user->getRoles();
        if ($user instanceof User) {
            foreach ($user->groups as $group) {
                $roles = [...$roles, ...$group->roles];
            }
        }

        return array_values(array_unique($roles));
    }
}
```

```yaml
collaborate:
    identity: App\Collaboration\CollaboratorProvider
```

The service id is any service; with autowiring, the class name is its id.

## What a user may edit

`Janalis\Collaborate\Access\DraftAccessCheckerInterface`:

```php
interface DraftAccessCheckerInterface
{
    public const string CREATE = 'create';
    public const string UPDATE = 'update';

    /** @param class-string $entityClass */
    public function canEdit(UserInterface $user, string $permission, string $entityClass): bool;
}
```

`$permission` is `create` on a new-record page and `update` on an edit page.
The bundle asks when a form renders (no shared draft when it says no) and
again on every draft request and every subscription that names the draft.
The default, `janalis_collaborate.access.role`, admits every user that holds
[`role`](./configuration#top-level), which each endpoint already requires.

```php
final readonly class DraftAccessChecker implements DraftAccessCheckerInterface
{
    public function __construct(private AuthorizationCheckerInterface $security) {}

    public function canEdit(UserInterface $user, string $permission, string $entityClass): bool
    {
        return $this->security->isGranted(strtoupper($permission), $entityClass);
    }
}
```

```yaml
collaborate:
    access: App\Collaboration\DraftAccessChecker
```

## Grants

Every collaboration request carries a grant the server signed with
`kernel.secret`; the client never names a topic, a record or a field on its
own.

| Grant | Issued by | Names | Lifetime |
|---|---|---|---|
| Page grant | `collaborate_layer()` | The user id, the page's cursor room (an HMAC of the route, its parameters and the query, without `_locale`, `_token` and `_switch_user`), the authorization hash | [`cursors.grant_ttl`](./configuration#cursors), 10 hours |
| Field grant | the form, in its `data-collaborate` attribute | The user id, the draft, the record's class, `create` or `update`, every shared field and its schema, the authorization hash | [`drafts.grant_ttl`](./configuration#drafts), 10 hours |
| Subscriber JWT | `POST <prefix>/cursors/session` | The topics the page grant and the verified field grants admit | [`hub.subscription_ttl`](./configuration#hub), 5 minutes |

A grant is refused when its signature is wrong, it has expired, it names
another user, or the user's `authorization()` hash has changed. Field
grants are also refused when `canEdit()` now says no.

What happens then:

- **Subscription.** The page asks for a new subscriber JWT every
  [`hub.renew_interval`](./configuration#hub) (4 minutes). A field grant the
  server refuses is left out of the subscription; the form's next draft
  request meets the same refusal and renews the grant as described below.
- **Page grant refused** (401, 403 or 404 on the session request: the grant
  expired, or a new session replaced the CSRF token). The page loads itself
  once in the background with `X-Collaborate-Renew: 1` and takes the fresh
  grant and CSRF token from the new layer. The bundle puts back the flash
  messages that background render read, so a message queued for the user's
  next page is not lost. When that reload is refused too, every feature of
  the page stops following it.
- **Field grant refused or expired.** The first draft request it fails
  renews it once from the form's own URL (asked with
  `X-Collaborate-Refresh: 1`, see [Without EasyAdmin](./without-easyadmin#refresh-requests)),
  then sends the failed requests again. A renewed grant that names another
  user or authorization, or is refused in turn, ends the session: the bar
  says access expired and the local content stays on the page.

Collaboration requests also require a remembered or fully authenticated
session (`IS_AUTHENTICATED_REMEMBERED`), the role and a CSRF token: the
layer's (`collaborate-hub`) for session and cursor requests, the form's
(`collaborate-draft`) for draft requests.

## Field grants and large forms

A page sends a form's full field grant until the server has verified it;
later requests send only its 64-character signature, which the server
resolves from [`drafts.grant_cache`](./configuration#drafts) for
`drafts.signature_ttl` (15 minutes) after the last full verification. A
signature the server no longer knows is answered with 412, and the page
sends the grant in full once.

A form whose field grant would not fit in
[`drafts.max_request_bytes`](./configuration#drafts) beside one update is
edited without a shared draft; a warning naming its entity is logged on the
`collaborate` channel.
