# Release Notes

## Version 2.0.3
**Fixes**
   - Fix a type error in logout (if `EnableDiscourseLogout` is configured true)
     that arises when running on PHP 8.  PHP 7.4 does a silent type-coercion
     in this case, hiding the bug earlier.
     (Thanks again to Joel Uckelman for the report and fix.)
---

## Version 2.0.2
**Fixes**
   - Fix the schemaVersion check boilerplate in SQL patch files so that it
     works on mysql/mariadb (as well as postgresql and sqlite3, as before).
     (Thanks to Joel Uckelman for the report and fix.)
   - Remove `composer.lock` from the repo.  (It should never have been
     committed in the first place.)
---

## Version 2.0.1
**Fixes**
   - Replace instances of `AuthManager::singleton()`, which was deprecated in
     MediaWiki 1.35 and removed completely in MediaWiki 1.37.  (Thanks to
     Raj Rathore for reporting the problem and for verifying that this
     extension now works with MW 1.37.)
---

## Version 2.0.0
***Upgrading***
 - This release has new minimum prerequisites:
   - MediaWiki >= 1.35
   - PHP >= 7.4 (with curl and json extensions)
   - PluggableAuth extension ~5.7 (< 6.x)

   (The updated PHP and PluggableAuth requirements were actually expressed
   in the `composer.json` file back in Version 1.2.0, but now they are in
   `extension.json` as well.)

 - This release introduces changes to the configuration parameters;
   You will need to modify your `LocalSettings.php`.
   (See [`README.md`](README.md) for configuration parameter details.)

   | pre-2.0.0         |            | 2.0.0                  | default value                                   |
   |-------------------|:----------:|------------------------|-------------------------------------------------|
   | `SsoProviderUrl`  | split into | `DiscourseUrl` and     | *required (no default)*                         |
   |                   |            | `SsoProviderEndpoint`  | `/session/sso_provider`                         |
   | `SharedSecret`    | renamed    | `SsoSharedSecret`      | *required (no default)*                         |
   | `AutoRelogin`     | renamed    | `EnableAutoRelogin`    | `false`                                         |
   | `LogoutDiscourse` | renamed    | `EnableDiscourseLogout`| `false`                                         |
   |                   | new        | `LogoutApiEndpoint`    | `/admin/users/{id}/log_out.json`                |
   |                   | new        | `LogoutApiUsername`    | `system`                                        |
   |                   | new        | `LogoutApiKey`         | *required if `EnableDiscourseLogout` is `true`* |

 - This release introduces database schema changes:
   - _Make a backup of your database._
   - Run MediaWiki's `maintenance/update.php` after upgrading to this release.
   - Do not expect to be able to downgrade to the previous major release.

**Features**
   - Set up a framework for managing this extension's database schema,
     to clearly track versions and implement updates.
   - Add a unique index/constraint to linkage table, to prevent multiple
     Discourse user-id's becoming linked to a single MediaWiki user-id.
   - Bump up prerequisites (uniformly):
     - MediaWiki >= 1.35
     - PHP >= 7.4 (with curl and json extensions)
     - PluggableAuth extension >= 5.7
   - Rename/reorganize some of the configuration parameters (see
     ***Upgrading*** above).  One improvement:  only the base URL for the
     Discourse server needs to be provided now; the location of the SSO
     provider endpoint has a sensible default.
   - Introduce new configuration parameters for Discourse logout.

**Fixes**
   - Move growing release notes out of README.md and into this separate file.
   - Add a missing `new` (when throwing an exception).
   - Heed the deprecation warning about calling upsert() with an "old-style"
     `$uniqueKeys` parameter.
   - Correctly check that required configuration parameters (those without
     sensible default values) have been configured with values.
   - Get logout propagation to Discourse working again; it was broken by
     changes in MW logout flow introduced between 1.31 and 1.35.  We now
     use Discourse's log_out API.
   - Fix the metadata in the extension description message.
---

## Version 1.2.0
**Features**
   - Now with a proper `composer.json` file, this extension can (and should)
     be installed with `composer`.

**Fixes**
   - Stop distributing the cruft `composer.lock` file that no one needs.
---

## Version 1.1.0
**Features**
   - New "Auto Re-login" feature, controlled by new
     `$wgDiscourseSsoConsumer_AutoRelogin` config parameter.
---

## Version 1.0.2
**Fixes**
   - Handle Discourse SSO credentials that do not provide a (real)name.
---

## Version 1.0.1
**Fixes**
   - Correctly handle the delayed provisioning of a new user id when a
     new user is (auto)created in MediaWiki in response to authenticating a
     previously unseen Discourse user.
---

## Version 1.0
**Initial version**
