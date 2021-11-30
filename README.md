# Discourse SSO Consumer for MediaWiki

`DiscourseSsoConsumer` is a
[MediaWiki](https://www.mediawiki.org/wiki/MediaWiki) extension which
allows a MediaWiki site to authenticate users via the built-in SSO-provider
functionality of a [Discourse](https://discourse.org/) discussion forum.

The `DiscourseSsoConsumer` extension is itself a plugin for MediaWiki's
[`PluggableAuth`](https://www.mediawiki.org/wiki/Extension:PluggableAuth)
extension.

## Installation

The recommended installation method is to use
[`composer`](https://getcomposer.org/).  This will automatically install any
dependencies, e.g.,
[`PluggableAuth`](https://www.mediawiki.org/wiki/Extension:PluggableAuth).

 > You can install this extension by hand (e.g., `git clone` this repository
 > into your site's `extensions/` directory), but then you will have to
 > manage its dependencies by hand as well.

 * Go to your MediaWiki installation directory and run two `composer` commands:
   ```
   $ cd YOUR-WIKI-INSTALL-DIRECTORY
   $ COMPOSER=composer.local.json composer require --no-update centertap/discourse-sso-consumer
   $ composer update centertap/discourse-sso-consumer --no-dev --optimize-autoloader
   ```
   If you want to pin the major version of this extension (so that future
   updates do not inadvertently introduce breaking changes), change the first
   command to something like this (e.g., for major revision "194":
   ```
   $ COMPOSER=composer.local.json composer require --no-update centertap/discourse-sso-consumer:^194.0.0
   ```
 * Edit your site's `LocalSettings.php` to load the extension(s):
   ```php
   ...
   wfLoadExtension( 'PluggableAuth' );
   wfLoadExtension( 'DiscourseSsoConsumer' );
   ...
   ```
 * Run `update.php` so that a new table is added to your site's database:
   ```
   $ cd YOUR-WIKI-INSTALL-DIRECTORY
   $ cd maintenance
   $ php update.php
   ```
 * Configure the extension(s).  (See below.)

## Configuration

The complete set of configuration parameters is listed below.  At a
minimum, you will need to set the `SsoProviderUrl` and `SharedSecret`
parameters, in concert with the Discourse site.  (On the Discourse site,
you will need to set `enable discourse connect provider` and `discourse
connect provider secrets` under `Admin -> Settings -> Login`.)

As mentioned in the `PluggableAuth` documentation, you will likely want to
configure the MediaWiki permissions to allow extensions to automatically
create new local accounts for authenticated users.  Add something like this
to `LocalSettings.php`:

  >```php
  ># Allow auth extensions to generate new local users.
  >$wgGroupPermissions['*']['autocreateaccount'] = true;
  >```

When `DiscourseSsoConsumer` loads, it will automatically plug itself into
the `PluggableAuth` extension.  You may want to tune the configuration of
[`PluggableAuth`](https://www.mediawiki.org/wiki/Extension:PluggableAuth),
in particular:

 * `$wgPluggableAuth_EnableAutoLogin`
 * `$wgPluggableAuth_EnableLocalLogin`
 * `$wgPluggableAuth_EnableLocalProperties`
 * `$wgPluggableAuth_ButtonLabelMessage`
 * `$wgPluggableAuth_ButtonLabel`

You may also want to adjust `$wgObjectCacheSessionExpiry`, which controls
how long it takes for an inactive user to be automatically logged out.
See `$wgDiscourseSsoConsumer_AutoRelogin` below for more details.

### Configuration Parameters

 * `$wgDiscourseSsoConsumer_SsoProviderUrl`
   * *mandatory string - no default value*
   * Specifies the URL of the SSO provider endpoint of the Discourse server,
     typically of the form
     `https://DISCOURSE-SERVER.EXAMPLE.ORG/session/sso_provider`.

 * `$wgDiscourseSsoConsumer_SharedSecret`
   * *mandatory string - no default value*
   * Specifies the secret shared with the Discourse server, via its
     `discourse connect provider secrets` setting.

 * `$wgDiscourseSsoConsumer_LinkExistingBy`
   * default value: `[ ]` (empty array)
   * Array of zero or more instances of `email` and/or `username` in
     any order, specifying how to link Discourse credentials to existing
     MediaWiki accounts.

     `DiscourseSsoConsumer` keeps track of the association of Discourse
     user id's to MediaWiki user id's.  When it encounters Discourse
     credentials with a novel id, it will attempt to find a matching
     MediaWiki user by iterating through the methods specified in
     `LinkExistingBy`.  The `email` method will look for a matching email
     address.  The `username` will look for a matching canonicalized
     username.  If the list is exhausted without finding a match (or if no
     methods are specified), a new Mediawiki account will be created.

 * `$wgDiscourseSsoConsumer_ExposeName`
   * default value: `false`
   * Specifies whether or not a Discourse user's full name will be
     exposed to MediaWiki.  The privacy controls of Discourse and
     MediaWiki are different and difficult to harmonize.  If names are
     not completely public in the Discourse site policy, it is probably
     best not to expose them to MediaWiki at all.

     This setting affects the realname reported to `PluggableAuth`, which
     will by default update the realname of the account.  If `ExposeName`
     is `true`, the local realname will be set to the name in the Discourse
     SSO credentials; if `false`, the local realname will be set to an
     empty string.

     See `$wgPluggableAuth_EnableLocalProperties` if you want it to leave
     the local realname alone completely.

 * `$wgDiscourseSsoConsumer_ExposeEmail`
   * default value: `false`
   * Specifies whether or not a user's email address will be exposed to
     MediaWiki.  The privacy controls of Discourse and MediaWiki are
     different and difficult to harmonize.  If email addresses are not
     completely public in the Discourse site policy, it is probably best
     not to expose them to MediaWiki at all.

     This setting affects the email address reported to `PluggableAuth`,
     which will by default update the email address of the account.  If
     `ExposeEmail` is `true`, the local email address will be set to the
     email address in the Discourse SSO credentials; if `false`, the local
     email address will be set to an empty string.

     See `$wgPluggableAuth_EnableLocalProperties` if you want it to leave
     the local email address alone completely.

 * `$wgDiscourseSsoConsumer_GroupMaps`
   * *optional - no default value*
   * Specifies how MediaWiki group memberships should be derived from
     Discourse credentials.  If set, it should be an array of the form

     ```php
     [ 'MW-GROUP-1' => [ 'D-GROUP-X', 'D-GROUP-Y', ... ],
       'MW-GROUP-2' => [ 'D-GROUP-A', 'D-GROUP-B', ... ], ]
     ```

     where each entry specifies that membership in the given MediaWiki
     group is strictly determined by membership in at least one of the
     listed Discourse groups.  Membership in the specified groups will be
     toggled according to the SSO credentials on each login; groups which
     are not mentioned in `GroupMaps` will not be touched.

     The two special tokens `@ADMIN@` and `@MODERATOR@` represent the
     `is_admin` and `is_moderator` flags in the SSO credentials.  For example,

     ```php
     $wgDiscourseSsoConsumer_GroupMap = [ 'sysop' => [ '@ADMIN@' ],
                                          'bureaucrat' => [ '@ADMIN@' ] ];
     ```

     will cause a user's membership in the `sysop` and `bureaucrat` groups
     to be set according to the `is_admin` flag in the SSO credentials
     upon every login.  Membership in any other MediaWiki groups will not
     be affected.

 * `$wgDiscourseSsoConsumer_LogoutDiscourse`
   * default value: `false`
   * If `true`, logging out of MediaWiki will also log the user out of
     Discourse (via a redirect to the SSO endpoint, requesting logout).

 * `$wgDiscourseSsoConsumer_AutoRelogin`
   * default value: `false`
   * If `true`, automatically re-login a previously-logged-in user if they
     become logged out due to, e.g., session timeout.
   * The purpose of this option is to provide a user experience more similar
     to Discourse itself, i.e., as long as the user is logged-in to Discourse,
     they will stay logged-in to Mediawiki.  Without this enabled, the user
     will be silently logged out of Mediawiki after a period of inactivity
     when their current session times out (by default, after one hour,
     as set by `$wgObjectCacheSessionExpiry`).
   * If you enable this option, you will probably want to _disable_
     `$wgPluggableAuth_EnableLocalLogin`, otherwise users will have to
     see the `Userlogin` interstitial page, and click through to
     Discourse SSO, every time a relogin occurs.
   * This option is different from `$wgPluggableAuth_EnableAutoLogin`.

     `PluggableAuth`'s `EnableAutoLogin`:
       * will always redirect unregistered users to the login flow;
       * will disable the user's `Log out` button;
       * will prevent anonymous access to the wiki.

     `DiscourseSsoConsumer`'s `AutoRelogin`:
       * will only redirect a previously logged-in user to the login flow;
       * will not disable logout (and will not try to re-login after a user
         does explicitly logout);
       * will not prevent anonymous access to the wiki.

      There is no point in having both modes enabled at the same time.
      Choose one or the other (or neither).

## Release Notes
### Version 1.0
 - Initial version
### Version 1.0.1
 - Bug fix:  correctly handle the delayed provisioning of a new user id when a
   new user is (auto)created in Mediawiki in response to authenticating a
   previously unseen Discourse user.
### Version 1.0.2
 - Bug fix:  handle Discourse SSO credentials that do not provide a (real)name.
### Version 1.1.0
 - Feature:  new "Auto Re-login" feature, controlled by new
   `$wgDiscourseSsoConsumer_AutoRelogin` config parameter.

## Known Bugs
See `TODO` comments in the source code.

## License

This work is licensed under GPL 2.0 (or any later version).

`SPDX-License-Identifier: GPL-2.0-or-later`
