# Locked settings

Most instance settings start from an environment variable in your `.env` file, and an admin can then change them in the admin panel or through the API. A value saved there is stored in the database and wins over the environment from then on. Because the admin panel saves every setting at once, the first save stores them all, and later changes to `.env` stop having any effect.

If your deployment's configuration should stay the only source for some settings (for example because they are managed by a configuration tool, or must match another service), name them in `MBIN_LOCKED_SETTINGS`:

```ini
MBIN_LOCKED_SETTINGS=KBIN_CONTACT_EMAIL,KBIN_SENDER_EMAIL,MBIN_SSO_ONLY_MODE
```

The value is a comma-separated list of setting names. Spaces around the names are ignored, and names are matched exactly, so they must be written in capitals as below. Leaving the variable unset or empty locks nothing.

A locked setting:

- always takes its value from the environment, even if the database holds a value saved earlier;
- is never written to the database;
- shows as disabled in the admin panel, with a note that the server's configuration sets it, and a submitted form cannot change it;
- is refused by the instance settings API (`PUT /api/instance/settings`) with a `400` response if a request tries to change it. Sending its current value, as part of a full set of settings, is accepted.

A value saved for a setting before it was locked stays in the database and is ignored. If you remove the setting from `MBIN_LOCKED_SETTINGS`, that saved value applies again.

## Which settings can be locked

Only a setting that falls back to an environment variable can be locked, because a locked setting needs a value to read. These can:

| Setting                                   | Environment variable                      |
|-------------------------------------------|-------------------------------------------|
| `KBIN_DOMAIN`                             | `KBIN_DOMAIN`                             |
| `KBIN_TITLE`                              | `KBIN_TITLE`                              |
| `KBIN_META_TITLE`                         | `KBIN_META_TITLE`                         |
| `KBIN_META_KEYWORDS`                      | `KBIN_META_KEYWORDS`                      |
| `KBIN_META_DESCRIPTION`                   | `KBIN_META_DESCRIPTION`                   |
| `KBIN_DEFAULT_LANG`                       | `KBIN_DEFAULT_LANG`                       |
| `KBIN_CONTACT_EMAIL`                      | `KBIN_CONTACT_EMAIL`                      |
| `KBIN_SENDER_EMAIL`                       | `KBIN_SENDER_EMAIL`                       |
| `MBIN_DEFAULT_THEME`                      | `MBIN_DEFAULT_THEME`                      |
| `KBIN_JS_ENABLED`                         | `KBIN_JS_ENABLED`                         |
| `KBIN_FEDERATION_ENABLED`                 | `KBIN_FEDERATION_ENABLED`                 |
| `KBIN_REGISTRATIONS_ENABLED`              | `KBIN_REGISTRATIONS_ENABLED`              |
| `KBIN_HEADER_LOGO`                        | `KBIN_HEADER_LOGO`                        |
| `KBIN_CAPTCHA_ENABLED`                    | `KBIN_CAPTCHA_ENABLED`                    |
| `KBIN_FEDERATION_PAGE_ENABLED`            | `KBIN_FEDERATION_PAGE_ENABLED`            |
| `KBIN_ADMIN_ONLY_OAUTH_CLIENTS`           | `KBIN_ADMIN_ONLY_OAUTH_CLIENTS`           |
| `MBIN_SSO_ONLY_MODE`                      | `SSO_ONLY_MODE`                           |
| `MBIN_PRIVATE_INSTANCE`                   | `MBIN_PRIVATE_INSTANCE`                   |
| `MBIN_DOWNVOTES_MODE`                     | `MBIN_DOWNVOTES_MODE`                     |
| `MBIN_NEW_USERS_NEED_APPROVAL`            | `MBIN_NEW_USERS_NEED_APPROVAL`            |
| `MBIN_USE_FEDERATION_ALLOW_LIST`          | `MBIN_USE_FEDERATION_ALLOW_LIST`          |
| `MBIN_AUTHORIZED_FETCH`                   | `MBIN_AUTHORIZED_FETCH`                   |
| `MBIN_SSO_AVATAR_OVERRIDE`                | `MBIN_SSO_AVATAR_OVERRIDE`                |
| `KBIN_MERCURE_ENABLED`                    | `KBIN_MERCURE_ENABLED`                    |
| `KBIN_FEDERATED_SEARCH_ONLY_LOGGEDIN`     | `KBIN_FEDERATED_SEARCH_ONLY_LOGGEDIN`     |
| `MBIN_SIDEBAR_SECTIONS_RANDOM_LOCAL_ONLY` | `MBIN_SIDEBAR_SECTIONS_RANDOM_LOCAL_ONLY` |
| `MBIN_SIDEBAR_SECTIONS_USERS_LOCAL_ONLY`  | `MBIN_SIDEBAR_SECTIONS_USERS_LOCAL_ONLY`  |
| `MBIN_SSO_REGISTRATIONS_ENABLED`          | `MBIN_SSO_REGISTRATIONS_ENABLED`          |
| `MBIN_RESTRICT_MAGAZINE_CREATION`         | `MBIN_RESTRICT_MAGAZINE_CREATION`         |
| `MBIN_SSO_SHOW_FIRST`                     | `MBIN_SSO_SHOW_FIRST`                     |

That is every instance setting.

> [!WARNING]
> Mbin answers every request with an error, instead of serving pages, if `MBIN_LOCKED_SETTINGS` names something that is not a setting, or a setting that cannot be locked. The error names the entry, so a typo never leaves a setting silently unlocked.
