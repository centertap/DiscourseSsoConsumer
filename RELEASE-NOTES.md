# Release Notes

## Version 5.0.1

**Fixes**
 - Fix the schema-v0008 SQL initialization files to identify the resulting
   schema as version 8, not version 7!  This caused a failure when installing
   the extension from new; the patch files for incremental upgrades from
   earlier versions were already correct.  (Thanks to Github user @Stonley890
   for reporting the problem.)
---

## Version 5.0.0

***Upgrading***
 - This version is functionally identical to version 4.0.0, but it now requires
   MediaWiki >= 1.39, and should work with MW >= 1.41.  (It may still work with
   MW == 1.36/1.37/1.38, but that is untested and unsupported.)
 - There are no configuration or schema changes.

**Fixes**
 - Replace instances of `User::idFromName()`, which was deprecated in
   MediaWiki 1.37 and removed completely in MediaWiki 1.41.  (Thanks to
   Github user @Kelduum for reporting the problem.)
---

## Version 4.0.0

***Upgrading***
 - This version is functionally identical to version 3.0.0, but it works with
   (and requires) **PluggableAuth 6.3**.
 - There are no changes to the database schema.
 - There are no changes to the **DiscourseSsoConsumer** configuration.
 - You **will** need to make changes to your **PluggableAuth** configuration.
 - These variables are no longer used:
   - `$wgPluggableAuth_ButtonLabel`
   - `$wgPluggableAuth_ButtonLabelMessage`
 - Setting `$wgPluggableAuth_Config` is required.  Minimally:
```php
$wgPluggableAuth_Config = [
    'MY-BUTTON-LABEL' => [ 'plugin' => 'DiscourseSsoConsumer' ]
];
```
 - Replace `MY-BUTTON-LABEL` with a sensible label for the login button
   (e.g., whatever value you had used for `$wgPluggableAuth_ButtonLabel`).
 - You should be able to downgrade back to version 3.0.0 without any issues.
   (Of course, you will need to downgrade/reconfigure PluggableAuth, too!)
---

## Version 3.0.0

***Upgrading***

 - This release introduces database schema changes:
   - _Make a backup of your database._
   - Run MediaWiki's `maintenance/update.php` after upgrading to this release.
   - Do not expect to be able to downgrade to the previous major release.

 - This release introduces changes to the configuration parameters,
   including new parameters and an altogether new mechanism for
   customizing the parameters.  You **will** need to modify your
   `LocalSettings.php`.  See [`README.md`](README.md) for instructions
   on setting parameters, in particular
   [by using a hook function](README.md#configure-discoursessoconsumer-using-a-hook-function).

   | pre-3.0.0               | 3.0.0                                    | default value                      |
   |-------------------------|------------------------------------------|------------------------------------|
   | `DiscourseUrl`          | `['DiscourseUrl']`                       | *no default, always required*      |
   |                         |                                          |                                    |
   | &mdash;                 | `['Sso']['Enable']`                      | `false`                            |
   | `SsoProviderEndpoint`   | `['Sso']['ProviderEndpoint']`            | `'/session/sso_provider'`          |
   | `SsoSharedSecret`       | `['Sso']['SharedSecret']`                | `null` *(no default)*              |
   | `EnableAutoRelogin`     | `['Sso']['EnableAutoRelogin']`           | `false`                            |
   | &mdash;                 | `['Sso']['EnableSeamlessLogin']`         | `false`                            |
   |                         |                                          |                                    |
   | `LinkExistingBy`        | `['User']['LinkExistingBy']`             | `[]`                               |
   | `ExposeName`            | `['User']['ExposeName']`                 | `false`                            |
   | `ExposeEmail`           | `['User']['ExposeEmail']`                | `false`                            |
   | `GroupMaps`             | `['User']['GroupMaps']`                  | `null` *(optional, no default)*    |
   |                         |                                          |                                    |
   | `LogoutApiUsername`     | `['DiscourseApi']['Username']`           | `system`                           |
   | `LogoutApiKey`          | `['DiscourseApi']['Key']`                | `null` *(no default)*              |
   | `LogoutApiEndpoint`     | `['DiscourseApi']['LogoutEndpoint']`     | `'/admin/users/{id}/log_out.json'` |
   | `EnableDiscourseLogout` | `['DiscourseApi']['EnableLogout']`       | `false`                            |
   |                         |                                          |                                    |
   | &mdash;                 | `['Webhook']['Enable']`                  | `false`                            |
   | &mdash;                 | `['Webhook']['SharedSecret']`            | `null` *(no default)*              |
   | &mdash;                 | `['Webhook']['AllowedIpList']`           | `[]`                               |
   | &mdash;                 | `['Webhook']['IgnoredEvents']`           | `['user_created']`                 |
   |                         |                                          |                                    |
   | &mdash;                 | `['Logout']['OfferGlobalOptionToUser']`  | `false`                            |
   | &mdash;                 | `['Logout']['ForwardToDiscourse']`       | `false`                            |
   | &mdash;                 | `['Logout']['HandleEventFromDiscourse']` | `false`                            |

**Features**
   - Revamped configuration scheme and parameters, which is hopefully more
     clear and handles future new parameters more elegantly.
   - Webhook receiver in MediaWiki to process webhook events emitted by Discourse
     - Discourse-initiated global logout from MediaWiki
     - Immediate user parameter updates (e.g., group membership, authorization level)
     - Create users in MediaWiki as they are created in Discourse (i.e., even
       before they login to MediaWiki for the first time)
   - SeamlessLogin, for seamless initial login to MediaWiki if a user is
     already logged-in to Discourse (followed by AutoRelogin to keep them
     logged-in)
   - API to access the complete Discourse user records received via webhook

**Fixes**
   - Local (single-device) logout from MediaWiki can now (again) be forwarded
     to Discourse without causing a global (all-device) logout from Discourse.
   - Immediate group-membership updates via webhook help close a security hole:
     without this, a user whose privilege level was reduced could keep
     operating at the prior level just by keeping their MW session
     alive.

**Known Issues**
   - SeamlessLogin depends on a pull-request not yet accepted into Discourse
     as of this release of **DiscourseSsoConsumer**.
     See https://github.com/discourse/discourse/pull/22393
   - Discourse does not (yet?) emit `user` webhook events in all the situations
     in which it could/should.  See
     https://meta.discourse.org/t/missing-webhook-user-events-by-design-or-oversight/273579
---

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
