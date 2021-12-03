# Release Notes

## Version devel
**Fixes**
   - Move growing release notes out of README.md and into this separate file.
   - Add a missing `new` (when throwing an exception).
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
