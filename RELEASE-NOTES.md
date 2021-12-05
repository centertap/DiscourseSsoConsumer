# Release Notes

## Version devel
***Upgrading***
 - This release has new minimum prerequisites:
   - Mediawiki >= 1.35
   - PHP >= 7.4
   - PluggableAuth extension >= 5.7

   (The updated PHP and PluggableAuth requirements were actually expressed
   in the `composer.json` file back in Version 1.2.0, but now they are in
   `extension.json` as well.)

 - This release introduces database schema changes:
   - _Make a backup of your database._
   - Run MW's `maintenance/update.php` after upgrading to this release;
   - Do not expect to be able to downgrade to the previous major release.

**Features**
   - Set up a framework for managing this extension's database schema,
     to clearly track versions and implement updates.
   - Add a unique index/constraint to linkage table, to prevent multiple
     Discourse user-id's becoming linked to a single Mediawiki user-id.
   - Bump up prerequisites (uniformly):
     - Mediawiki >= 1.35
     - PHP >= 7.4
     - PluggableAuth extension >= 5.7

**Fixes**
   - Move growing release notes out of README.md and into this separate file.
   - Add a missing `new` (when throwing an exception).
   - Heed the deprecation warning about calling upsert() with an "old-style"
     `$uniqueKeys` parameter.
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
     new user is (auto)created in Mediawiki in response to authenticating a
     previously unseen Discourse user.
---

## Version 1.0
**Initial version**
