# Discourse SSO Consumer for MediaWiki

**DiscourseSsoConsumer** is a
[MediaWiki](https://www.mediawiki.org/wiki/MediaWiki) extension which
allows a MediaWiki site to authenticate users via a
[Discourse](https://discourse.org/) discussion forum, using
the forum's Single Sign-On (SSO) provider functionality.
This extension aims to facilitate seamless integration between a wiki
and a discussion forum.

The **DiscourseSsoConsumer** extension is itself a plugin for MediaWiki's
[**PluggableAuth**](https://www.mediawiki.org/wiki/Extension:PluggableAuth)
extension.

Notable features of this **DiscourseSsoConsumer** are:
 * authentication of wiki users via Discourse login;
 * setting wiki user permissions according to Discourse groups;
 * linking Discourse users to existing wiki users via username or email,
   and/or creating new wiki users when needed;
 * access to Discourse user information for linked users;
 * seamless authentication when users navigate between wiki and Discourse;
 * "single sign-off" *(well... in one direction at least)*.

---

**Brought to you by...** [![CTAP](images/CTAP-powered-by-132x47.png)](https://www.centertap.org/)

This extension is developed by the
[Center for Transparent Analysis and Policy](https://www.centertap.org/),
a 501(c)(3) non-profit organization.  If this extension is useful
for your wiki, consider making a
[donation to support CTAP](https://www.centertap.org/how).
*You can be a provider for Discourse SSO Consumer!*

---

 * [Prerequisites](#prerequisites)
 * [Installation](#installation)
 * [Configuring Discourse](#configuring-discourse)
   * [DiscourseConnect Provider for SSO Authentication](#discourseconnect-provider-for-sso-authentication)
   * [Discourse API Key for Global Logout](#discourse-api-key-for-global-logout)
   * [Webhook for User Events](#webhook-for-user-events)
 * [Configuring MediaWiki](#configuring-mediawiki)
    * [Configure PluggableAuth and MediaWiki in General](#configure-pluggableauth-and-mediawiki-in-general)
    * [Configure DiscourseSsoConsumer using a hook function ⚠️](#configure-discoursessoconsumer-using-a-hook-function)
    * [Catalog of Configuration Parameters](#catalog-of-configuration-parameters)
 * [Tips, Hints, More Details](#tips-hints-more-details)
    * [Logging-out](#logging-out)
    * [MediaWiki Session Lifetimes](#mediawiki-session-lifetimes)
    * [Logging-in Automatically](#logging-in-automatically)
    * [Webhook, User Records, and the Extension API](#webhook-user-records-and-the-extension-api)
 * [Release Notes](#release-notes)
 * [Known Bugs/Issues](#known-bugs-issues)
 * [License](#license)

---

<a name="prerequisites"></a>

## Prerequisites

To make use of **DiscourseSsoConsumer**, you will need:

 * PHP >= 7.4.0
 * MediaWiki >= 1.39
   * ***DiscourseSsoConsumer** has been developed/tested with MW 1.39.
     See [Known Bugs/Issues](#known-bugs-issues) for possible issues
     with newer versions.*
 * [PluggableAuth](https://www.mediawiki.org/wiki/Extension:PluggableAuth)
   extension
   * *PluggableAuth ~6.3 is required by DiscourseSsoConsumer 4.x.x*
 * a [Discourse](https://discourse.org/) discussion server

---

<a name="installation"></a>

## Installation

The recommended installation method is to use
[`composer`](https://getcomposer.org/).  This will automatically install any
dependencies, e.g.,
[**PluggableAuth**](https://www.mediawiki.org/wiki/Extension:PluggableAuth).

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
   command to something like this (e.g., for major revision "194"):
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
 * Run `update.php` to add this extension's tables to your wiki's database:
   ```
   $ cd YOUR-WIKI-INSTALL-DIRECTORY
   $ cd maintenance
   $ php update.php
   ```
 * Continue onward to configure Discourse and MediaWiki.

---

<a name="configuring-discourse"></a>

## Configuring Discourse

There are three independent aspects of Discourse which you may need
to configure, depending on what **DiscourseSsoConsumer** functionality
you want to use.

<a name="discourseconnect-provider-for-sso-authentication"></a>

### DiscourseConnect Provider for SSO Authentication

If you want MediaWiki to actually authenticate users via Discourse,
you will need to enable the "DiscourseConnect Provider"
on your Discourse server functionality and set up a shared-key.

Go to `Admin -> Settings -> Login` and:
 * Set `enable discourse connect provider`.
 * Add your wiki site and a secret to `discourse connect provider secrets`.
   * Choose a secure shared secret (i.e., use a password generator).
   * Remember the secret for your MediaWiki configuration.

![*discourse settings image*](images/discourse-settings.png)

<a name="discourse-api-key-for-global-logout"></a>

### Discourse API Key for Global Logout

If you want to allow MediaWiki to trigger global logouts on Discourse
(i.e., log a user out of all devices), then
you will also need to create an API key on Discourse for its `log_out` API.

Go to `Admin -> API` and:
 * Click `New API Key`.
 * Enter:
   * *Description:* (anything you want)
   * *User Level:* **Single User**
   * *User:* **system**
   * *Scope:* **Granular**
   * *Scopes:* enable **users: log out**
 * Hit `Save` and keep a copy of the generated key for
   `['DiscourseApi']['Key']` in MediaWiki.

Make sure your MediaWiki server can connect directly to your Discourse
server.  The `log_out` API request is made directly by the MediaWiki server
to the Discourse server (unlike SSO requests for logging in, which are
redirected through the user's browser).

<a name="webhook-for-user-events"></a>

### Webhook for User Events

If you want MediaWiki to get updated user information in real-time (not just
when a user logs in), you can configure a webhook on Discourse, telling it
to send user events to MediaWiki.

First:
 * Figure out the URL which points to the `Special:DiscourseSsoConsumerWebhook`
   page on your wiki.  (For example, something like
   `https://wiki.example.org/view/Special:DiscourseSsoConsumerWebhook`.
 * Choose a secure shared-secret (i.e., use a password generator).
 * Remember the secret for your MediaWiki configuration.

Next, go to `Admin -> API` on Discourse and:
 * Select the `Webhooks` tab.
 * Click `New Webhook`.
 * Enter:
   * *Payload URL:* (the URL to `Special:DiscourseSsoConsumerWebhook` on your wiki)
   * *Content Type:* **application/json**
   * *Secret:*  (the shared-secret you created)
   * *Which events should trigger this webhook?* **Select individual events.**
     * **User Event**
   * *Check TLS certificate of payload url:* **checked** (recommended)
   * *Active:* **checked**
 * Hit `Save` and remember the shared-secret for MediaWiki.

Make sure your Discourse server can connect directly to your MediaWiki
server.  The webhook requests are made directly by the Discourse server
to the MediaWiki server (unlike SSO requests for logging in, which are
redirected through the user's browser).

---

<a name="configuring-mediawiki"></a>

## Configuring MediaWiki

Setting up **DiscourseSsoConsumer** involves setting its configuration 
parameters, as well as parameters for **PluggableAuth** and MediaWiki
in general.

<a name="configure-pluggableauth-and-mediawiki-in-general"></a>

### Configure PluggableAuth and MediaWiki in General

At a minimum, you will need to tell
[**PluggableAuth**](https://www.mediawiki.org/wiki/Extension:PluggableAuth),
that it should use **DiscourseSsoConsumer**,
by providing an entry in `$wgPluggableAuth_Config`:
```php
$wgPluggableAuth_Config = [
    'MY-BUTTON-LABEL' => [ 'plugin' => 'DiscourseSsoConsumer' ]
];
```
Replace `MY-BUTTON-LABEL` with whatever string you would like to see
in the wiki's login button.  (Even if `$wgPluggableAuth_EnableLocalLogin`
is disabled, the wiki login page may still appear in certain situations.
So, it is worth choosing a sensible value for this.)

You will probably want to tune the configuration of **PluggableAuth**,
in particular:

 * `$wgPluggableAuth_EnableAutoLogin`
   * *See [Logging-in Automatically](#logging-in-automatically) before
     enabling this.*
 * `$wgPluggableAuth_EnableLocalLogin`
   * If this is *disabled*, then clicking the wiki login button will
     navigate directly to the Discourse login window (skipping the wiki's
     own login page).
 * `$wgPluggableAuth_EnableLocalProperties`
   * If this is enabled, then Discourse real names and email addresses will
     not be synchronized with MediaWiki; the wiki attributes will be left alone.

As mentioned in the **PluggableAuth** documentation, you will likely want to
configure the MediaWiki permissions to allow extensions to automatically
create new wiki accounts for authenticated users.  Add something like this
to `LocalSettings.php`:

  >```php
  ># Allow auth extensions to generate new local users.
  >$wgGroupPermissions['*']['autocreateaccount'] = true;
  >```

You will likely want to tune these MediaWiki parameters as well:
 * [`$wgObjectCacheSessionExpiry`](https://www.mediawiki.org/wiki/Manual:$wgObjectCacheSessionExpiry)
   * Sets the timeout duration for inactive sessions.
 * [`$wgExtendedLoginCookieExpiration`](https://www.mediawiki.org/wiki/Manual:$wgExtendedLoginCookieExpiration)
   * Sets the lifetime of "Keep me logged in" logins.
 * [`$wgRememberMe`](https://www.mediawiki.org/wiki/Manual:$wgRememberMe)
   * Sets the "Keep me logged in" mode of operation.

See [Logging-in Automatically](#logging-in-automatically) for more details
on these last three.


<a name="configure-discoursessoconsumer-using-a-hook-function"></a>

### Configure DiscourseSsoConsumer using a hook function ⚠️

Instead of directly setting a global variable in `LocalSettings.php`,
the preferred way to configure **DiscourseSsoConsumer** parameters
is by using the `DiscourseSsoConsumer_Configure` hook, as in the
example below:
```php
$wgHooks['DiscourseSsoConsumer_Configure'][] =
    function ( array &$config ) {
      $config['DiscourseUrl'] = 'https://discourse.example.org';
      $config['Sso']['Enable'] = true;
      $config['Sso']['SharedSecret'] = 'SETECAstronomy';
      $config['Sso']['EnableSeamlessLogin'] = true;
      $config['Sso']['EnableAutoRelogin'] = true;
      $config['GroupMaps'] = [
          'sysop' => [ '@ADMIN@' ],
          'bureaucrat' => [ '@ADMIN@' ],
          'quitetrusted' => [ 'trust_level_3',
                              'trust_level_4',
          ],
      ];
      $config['Webhook']['Enable'] = true;
      $config['Webhook']['SharedSecret'] = 'MyVoiceIsMyPassword';
      $config['Webhook']['AllowedIpList'][] = '192.168.22.11';
      return true;
    };
```

Your hook function will be called with the array `$config` pre-populated
with the built-in defaults of the extension, which you may then modify
as you see fit.  It is recommended to modify this array, instead of
completely replacing it, in order to benefit from any new default
parameters added to the extension in future versions.

Be sure to include the ampersand `&` in the function's signature:
`function ( array &$config )`! ⚠️

 | *Why use a hook function?* |
 |----------------------------|
 | MediaWiki attempts to merge default values into an extension's config variables *after* executing `LocalSettings.php`.  The first problem with this is that there is no way to access the default values when `LocalSettings.php` is executed.  The second problem is that none of the available, hard-coded "merge strategies" work for our nested config structure.  Using a hook solves both of these problems; within the hook function, the admin gets access to the default parameters and can modify those defaults and build on top of them however they want. |


<a name="catalog-of-configuration-parameters"></a>

### Catalog of Configuration Parameters

Technically, the **DiscourseSsoConsumer** extension has only a single
configuration parameter: `$wgDiscourseSsoConsumer_Config`.  This is an
array, though, and the entire configuration goes into that single array.
By setting the configuration via a hook function (see above), one never
needs to care about the actual variable name of the array.

The configuration is hierarchical, expressed via nested arrays.  Hence,
some values are at the top-level --- `$config['key']` --- and some are
deeper --- `$config['key1']['key2']`.

Here is a summary of the available parameters; details follow below.

| parameter                                | default value                      |
|------------------------------------------|------------------------------------|
| `['DiscourseUrl']`                       | *no default, always required*      |
|                                          |                                    |
| `['Sso']['Enable']`                      | `false`                            |
| `['Sso']['ProviderEndpoint']`            | `'/session/sso_provider'`          |
| `['Sso']['SharedSecret']`                | `null` *(no default)*              |
| `['Sso']['EnableAutoRelogin']`           | `false`                            |
| `['Sso']['EnableSeamlessLogin']`         | `false`                            |
|                                          |                                    |
| `['User']['LinkExistingBy']`             | `[]`                               |
| `['User']['ExposeName']`                 | `false`                            |
| `['User']['ExposeEmail']`                | `false`                            |
| `['User']['GroupMaps']`                  | `null` *(optional, no default)*    |
|                                          |                                    |
| `['DiscourseApi']['Username']`           | `system`                           |
| `['DiscourseApi']['Key']`                | `null` *(no default)*              |
| `['DiscourseApi']['LogoutEndpoint']`     | `'/admin/users/{id}/log_out.json'` |
| `['DiscourseApi']['EnableLogout']`       | `false`                            |
|                                          |                                    |
| `['Webhook']['Enable']`                  | `false`                            |
| `['Webhook']['SharedSecret']`            | `null` *(no default)*              |
| `['Webhook']['AllowedIpList']`           | `[]`                               |
| `['Webhook']['IgnoredEvents']`           | `['user_created']`                 |
|                                          |                                    |
| `['Logout']['OfferGlobalOptionToUser']`  | `false`                            |
| `['Logout']['ForwardToDiscourse']`       | `false`                            |
| `['Logout']['HandleEventFromDiscourse']` | `false`                            |


Here are the details:

 * `['DiscourseUrl']`
   * string, *mandatory - no default value*
   * Specifies the base URL of the Discourse server, typically of the form
     `https://my-discourse-server.example.org` (no trailing slash).

 * `['Sso']` - subconfig concerning Discourse's SSO API, also known as
   "DiscourseConnect".  This is the core functionality for authenticating
   MediaWiki users against Discourse.

   * `['Sso']['Enable']`
     * boolean, default value: `false`
     * Whether or not to enable single-sign-on functionality, i.e.,
       authentication via the Discourse server.

   * `['Sso']['SharedSecret']`
     * string, *mandatory - no default value*
     * Specifies the secret shared with the Discourse server, via its
       `discourse connect provider secrets` setting.

   * `['Sso']['ProviderEndpoint']`
     * string, default value: `/session/sso_provider`
     * Specifies the SSO provider endpoint of the Discourse server; a complete
       URL will be constructed by prepending `DiscourseUrl`.

   * `['Sso']['EnableSeamlessLogin']`
     * boolean, default value: `false`
     * If `true`, automatically login never-before-seen visitors to the wiki,
       if they are already logged-in to Discourse.
     * See [Logging-in Automatically](#logging-in-automatically) below
       for more details on this function.

   * `['Sso']['EnableAutoRelogin']`
     * boolean, default value: `false`
     * If `true`, automatically re-login a previously-logged-in user if they
       become logged out due to, e.g., session timeout.
     * See [Logging-in Automatically](#logging-in-automatically) below
       for more details on this function.

 * `['User']` - subconfig for controlling how user identities are mapped
   and synchronized from Discourse to MediaWiki.  This synchronizatio occurs
   during both SSO authentication and webhook event processing.

   * `['User']['LinkExistingBy']`
     * array, default value: `[]` (empty array)
     * Array of zero or more instances of the strings `email` and/or `username`
       in any order, specifying how to link Discourse credentials to existing
       MediaWiki accounts.

       **DiscourseSsoConsumer** keeps track of the association of Discourse
       user id's to MediaWiki user id's.  When it encounters Discourse
       credentials with a novel id, it will attempt to find a matching
       MediaWiki user by iterating through the methods specified in
       `LinkExistingBy`.  The `email` method will look for a matching email
       address.  The `username` will look for a matching canonicalized
       username.  If the list is exhausted without finding a match (or if no
       methods are specified), a new MediaWiki account will be created,
       if possible.  (If no account can be found/created, authentication
       will fail.)

       Note that the `email` method requires that `['User']['ExposeEmail']`
       (see below) is also enabled.

   * `['User']['ExposeName']`
     * boolean, default value: `false`
     * Specifies whether or not a Discourse user's full name will be
       exposed to MediaWiki.  The privacy controls of Discourse and
       MediaWiki are different and difficult to harmonize.  If names are
       not completely public in the Discourse site policy, it is probably
       best to not expose them to MediaWiki at all.

       This setting affects the realname reported to **PluggableAuth**, which
       will by default update the realname of the account.  If `ExposeName`
       is `true`, the wiki realname will be set to the name in the Discourse
       SSO credentials; if `false`, the wiki realname will be set to an
       empty string.

       Either way, **DiscourseSsoConsumer** will take control of the user's
       wiki realname.
       See `$wgPluggableAuth_EnableLocalProperties` if you want the realname
       to be left alone by **DiscourseSsoConsumer**.

   * `['User']['ExposeEmail']`
     * boolean, default value: `false`
     * Specifies whether or not a user's email address will be exposed to
       MediaWiki.  The privacy controls of Discourse and MediaWiki are
       different and difficult to harmonize.  If email addresses are not
       completely public in the Discourse site policy, it is probably best
       to not expose them to MediaWiki at all.

       This setting affects the email address reported to **PluggableAuth**,
       which will by default update the email address of the account.  If
       `ExposeEmail` is `true`, the wiki email address will be set to the
       email address in the Discourse SSO credentials; if `false`, the wiki
       email address will be set to an empty string.

       Either way, **DiscourseSsoConsumer** will take control of the user's
       wiki email address.
       See `$wgPluggableAuth_EnableLocalProperties` if you want the email
       address to be left alone by **DiscourseSsoConsumer**.

   * `['User']['GroupMaps']`
     * array, *optional - no default value*
     * Specifies how MediaWiki group memberships should be derived from
       Discourse credentials.  If set, it should be an array of the form

       ```php
       [ 'MW-GROUP-1' => [ 'D-GROUP-X', 'D-GROUP-Y', ... ],
         'MW-GROUP-2' => [ 'D-GROUP-A', 'D-GROUP-B', ... ], ]
       ```

       where each entry specifies that membership in the given MediaWiki group
       (e.g., `MW-GROUP-1`) is strictly determined by membership in at least
       one of the listed Discourse groups (e.g., `D-GROUP-X`).  Membership in
       the specified groups will be updated whenever **DiscourseSsoConsumer**
       processes group information from Discourse; groups which are not
       mentioned as keys in `GroupMaps` will not be affected.

       Two special tokens, `@ADMIN@` and `@MODERATOR@`, can be used to
       represent the *admin* and *moderator* status of the Discourse user.
       For example,

       ```php
       [ 'sysop' => [ '@ADMIN@' ],
         'bureaucrat' => [ '@ADMIN@' ] ];
       ```

       will cause a user's membership in the `sysop` and `bureaucrat` groups
       to be set according to their *admin* status in Discourse, and
       membership in any other MediaWiki groups will not be affected.

 * `['DiscourseApi']` - subconfig for functionality involving Discourse API's
   (backend requests from MediaWiki to Discourse)

   * `['DiscourseApi']['Username']`
     * string, default value: `system`
     * Username for making Discourse API requests.
       Must be set if `['EnableLogout']` is `true`.

   * `['DiscourseApi']['Key']`
     * string, *no default value*
     * Key for making Discourse API requests, generated on Discourse server
       when the API is configured.  Must be set if `['EnableLogout']` is `true`.

   * `['DiscourseApi']['EnableLogout']`
     * boolean, default value: `false`
     * If `true`, logging out of MediaWiki will also log the user out of
       Discourse globally (i.e., all sessions on all devices).
       See [Logging-out](#logging-out) below for more details on this function.

   * `['DiscourseApi']['LogoutEndpoint']`
     * string, default value: `/admin/users/{id}/log_out.json`
     * Endpoint for the log_out API of the Discourse server; complete
       URL will be constructed by prepending `['DiscourseUrl']`.  The
       substring `{id}` will be replaced by the logged-out user's id.
       Must be set if `['EnableLogout']` is `true`.

 * `['Webhook']` - subconfig for functionality involving Discourse webhooks
   (backend requests from Discourse to MediaWiki)

   * `['Webhook']['Enable']`
     * boolean, default value: `false`
     * If `false`, webhook requests from Discourse will be ignored.
       If `true`, `['SharedSecret']` and `['AllowedIpList']` must also be set.

   * `['Webhook']['SharedSecret']`
     * string, *no default value*
     * The secret used when configuring the webhook on Discourse.  Must be
       non-null and non-empty if webhook processing is enabled.

   * `['Webhook']['AllowedIpList']`
     * array of string, default value: `[]`
     * List of strings containing trusted IP addresses for the Discourse server.
       Must be non-empty if webhook processing is enabled.  Only requests
       received from a source on this list will be processed.

   * `['Webhook']['IgnoredEvents']`
     * array of keyword strings, default value: `['user_created']`
     * List of Discourse user events that should be *ignored*.  Valid keywords
       are:
       * `user_created`
       * `user_confirmed_email`
       * `user_approved`
       * `user_logged_in`
       * `user_updated`
       * `user_logged_out`
       * `user_destroyed`

       `user_created` is ignored by default because unconfirmed users can be
       automatically scrubbed/deleted.  It makes more sense to wait until a
       `user_confirmed_email` event before linking/creating a user in
       MediaWiki.

 * `['Logout']` - subconfig for functionality involving logging-out actions
   and events

   * `['Logout']['OfferGlobalOptionToUser']`
     * boolean, default value: `false`
     * If `true`, a checkbox will appear on the wiki logout confirmation page
       giving the user the option to log out from all devices/sessions, not
       just the one they are using for the logout.
     * See [Logging-out](#logging-out) below for more details.

   * `['Logout']['ForwardToDiscourse']`
     * boolean, default value: `false`
     * If `true`, logout actions on the wiki will be propagated to Discourse.
     * See [Logging-out](#logging-out) below for more details.

   * `['Webhook']['HandleEventFromDiscourse']`
     * boolean, default value: `false`
     * If `true`, then a `user_logged_out` event for a user will cause all of
       that user's wiki sessions to be invalidated, logging them out globally
       from all devices.
     * See [Logging-out](#logging-out) below for more details.

----

<a name="tips-hints-more-details"></a>

## Tips, Hints, More Details

<a name="logging-out"></a>

### Logging-out

Logging out was a bit of an afterthought in the "DiscourseConnect" design,
so a complete *single sign-out* integration of MediaWiki with Discourse
is not possible yet.  But, we can get about 3/4's of the way there.

There are two scopes of logout:
 * *local*: invalidation of the session for a single browser/device,
   usually implying cleaning up the cookie state, too;
 * *global*: invalidation of all sessions for a user, i.e., logging
   them out of all devices.

There are two possible directions of logout flow:
 * *MediaWiki-initiated*: logout started on/by MediaWiki, and is
   forwarded to Discourse.
 * *Discourse-initiated*: logout started on/by Discourse, and is
   forwarded to MediaWiki;

There are four combinations, of which we can accomplish three:

| direction                  | local via...          | global via...            |
|----------------------------|-----------------------|--------------------------|
| MediaWiki &rarr; Discourse | ...SSO logout request | ...Discourse API request |
| Discourse &rarr; MediaWiki | ...`¯\_(ツ)_/¯`       | ...Webhook event         |

That one thing that we cannot do is to have a local logout from Discourse
*(user clicks Discourse logout button)* cause a local logout from
MediaWiki *(same browser is logged out from MediaWiki)*.

`['Logout']['HandleEventFromDiscourse']` controls whether or not a
global logout will be invoked in response to a `user_logged_out` event
from Discourse.  If this is enabled, then you must also enable
`['Webhook']['Enable']` and, of course, set up the webhook with Discourse.
(See [Webhook for User Events](#webhook-for-user-events).)

`['Logout']['ForwardToDiscourse']` controls whether or not any logout
events on MediaWiki are forwarded to Discourse.  If this is enabled, then you
must also enable `['Sso']['Enable']` and, of course, set up SSO with
Discourse.  (See
[DiscourseConnect Provider for SSO Authentication](#discourseconnect-provider-for-sso-authentication).)
You may also need to enable `['DiscourseApi']['EnableLogout']`; keep reading.

`['Logout']['OfferGlobalOptionToUser']` controls whether or not the user
is given the option to perform a global logout.  If this is enabled and
`['Logout']['ForwardToDiscourse']` is enabled, then you must also
enable `['DiscourseApi']['EnableLogout']`, because that is the
mechanism that will be used for the global logout.  (See
[Discourse API Key for Global Logout](#discourse-api-key-for-global-logout),
as well.)

<a name="cautions-about-discourse-user_logged_out-events"></a>

#### Cautions about Discourse `user_logged_out` events

Discourse emits a global `user_logged_out` webhook event in these situations:
 * A user clicks *Logout* and Discourse is configured for `strict log out`.
 * A user is *suspended* by an admin/moderator.
 * A user is *deleted* by an admin.  (Actually, in this case a `user_destroyed`
   event is emitted, but **DiscourseSsoConsumer** treats that like a
   `user_logged_out` event.)

However, even though the following conditions do cause a global logout on
Discourse, it does *not* emit `user_logged_out` events when:
 * A user uses *Log out all* via the Preferences/Security screen.
 * A user is *anonymized* by an admin.

This inconsistency is probably a bug in Discourse.

<a name="mediawiki-session-lifetimes"></a>

### MediaWiki Session Lifetimes

By default, Discourse will try to keep users logged-in forever.  (See its
`persistent sessions` and `maximum session age` settings, which default to
enabled and 60 days, respectively.)

MediaWiki does have a "Keep me logged in" option.  However:
 1. If a user does choose to log out of Discourse, there is no good way to
    automatically log the user out of MediaWiki also.
 2. If `$wgPluggableAuth_EnableLocalLogin` is disabled, users will *usually*
    never see the page with the "Keep me logged in" checkbox.  But, under
    certain error conditions, they will see that page, and seeing that
    checkbox will likely be confusing.

So, you may want to disable "Keep me logged in" on MediaWiki entirely.
On MW 1.35, this can be accomplished by setting `$wgExtendedLoginCookieExpiration`
to zero.

If this is disabled, then all inactive MediaWiki sessions will expire after
the timeout set by `$wgObjectCacheSessionExpiry`, which defaults to 1 hour.

**DiscourseSsoConsumer** can be configured to conveniently automatically
reauthenticate expired sessions; read about *AutoRelogin* below.


<a name="logging-in-automatically"></a>

### Logging-in Automatically

There are three "automatic login" modes available with **DiscourseSsoConsumer**.
They affect what happens when a user navigates to a page on the wiki.

 * **PluggableAuth**'s *AutoLogin* (`$wgPluggableAuth_EnableAutoLogin`)
   * will always redirect not-logged-in users to the login flow;
   * will disable the `Log out` button;
   * will prevent anonymous access to the wiki.
 * **DiscourseSsoConsumer**'s *AutoRelogin* (`['Sso']['EnableAutoRelogin']`)
   * will quietly reauthenticate a user via Discourse, but only if they
     *had already been logged-in to the wiki* and if they are still logged-in to Discourse
   * will not disable logout, and will not try to re-login after a user
     explicitly logs out;
   * will *not* prevent anonymous access to the wiki.
 * **DiscourseSsoConsumer**'s *SeamlessLogin* (`['Sso']['EnableSeamlessLogin']`)
   * will authenticate a user via Discourse on their *first* visit to the wiki,
     if they are already logged-in to Discourse; has no effect on later visits;
   * will not disable logout, and will not try to re-login after a user
     explicitly logs out;
   * will *not* prevent anonymous access to the wiki.

**PluggableAuth (PA)** *AutoLogin* is intended to be used on wikis which only
allow logged-in users.  Since PA's *AutoLogin* does not permit anonymous page
visits at all, there is no reason to enable the other modes alongside it.
(E.g., if a user's session expires, PA's *AutoLogin* will by itself ensure they
become authenticated again on the next visit.)  So, if you do enable PA's
*AutoLogin*, then do not enable either of the *DiscourseSsoConsumer* modes.

**DiscourseSsoConsumer**'s *AutoRelogin* is intended to try to keep an already
logged-in user logged-in in case their session expires, e.g., due to
timing-out from being idle.  When a previously logged-in user visits the wiki,
*AutoRelogin* mode will probe Discourse (via browser redirects) for the user's
authentication status.  If they are still logged-in to Discourse, they will
get a new wiki session.  If not, they will remain anonymous, and *AutoRelogin*
will not try again --- if the user wants to, they can hit the login button and
be redirected to Discourse to explicitly login.

The purpose of *AutoRelogin* is to provide a user experience more similar to
Discourse itself, i.e., as long as the user is logged-in to Discourse, they
will stay logged-in to MediaWiki.  Without this enabled, the user will be
silently logged out of MediaWiki after a period of inactivity when their
current session times out.  If you enable this option, you will probably want
to _disable_ `$wgPluggableAuth_EnableLocalLogin`, otherwise users will have to
see the `Userlogin` interstitial page, and click through to Discourse SSO,
every time a relogin occurs.

**DiscourseSsoConsumer**'s *SeamlessLogin* is intended to allow a user,
already logged-in to Discourse, to navigate to MediaWiki and be logged-in as
soon as they get there.  In other words, it tries to provide a *seamless*
transition from Discourse to MediaWiki.  *SeamlessLogin* only affects a user's
*very first* visit to the wiki (per device/browser), however.  If they are not
already logged-in to Discourse on that first visit, they will need to log-in
to the wiki explicitly.

It never makes sense to use PA's *AutoLogin* with either of the
**DiscourseSsoConsumer** modes, but it can certainly make sense to use the
**DiscourseSsoConsumer** together:

| SeamlessLogin | AutoRelogin | rationale                                                 |
|---------------|-------------|-----------------------------------------------------------|
| on            | on          | MW sessions should track Discourse sessions               |
| off           | on          | Require explicit MW login, but then try to stay logged in |
| on            | off         | `¯\_(ツ)_/¯`                                              |
| off           | off         | MW sessions managed independently of Discourse            |


<a name="webhook-user-records-and-the-extension-api"></a>

### Webhook, User Records, and the Extension API

When webhook processing is enabled and a user event is received, then
**DiscourseSsoConsumer** will receive a fairly complete record of user
data from Discourse.  **DiscourseSsoConsumer** will store this record
in the MediaWiki database, along with information about the event itself
(timestamp, event type, event id).

> *This data is stored verbatim as received from Discourse.
> The mappings defined by the `['User']` configuration are not applied here.*

Other extensions/functions can fetch and use this data by using a public
API provided by **DiscourseSsoConsumer**.  Look for `ApiV1/Connector.php`
in the source code for more details.

For example:
```php
use MediaWiki\Extension\DiscourseSsoConsumer\ApiV1\Connector;

function getUserAvatarUrlAndTimezone( User $user, int $size ) {
    $record = Connector::getUserRecord( $user );
    $template = $record->user_record->avatar_template ?? null;
    $avatarUrl = null;
    if ( $template !== null ) {
        $avatarUrl = str_replace( '{size}', (string)$size, $template );
    }
    $timezone = $record->user_record->user_option->timezone ?? null;
    return [ $avatarUrl, $timezone ];
}
```

For a description of the Discourse user data itself, your best bet is
to set up a webhook and use Discourse's webhook log to examine the JSON
contents of some events.  (Discourse conveniently logs the Request and
Response for each event of every webhook.)


---

<a name="release-notes"></a>

## Release Notes

See [`RELEASE-NOTES.md`](RELEASE-NOTES.md).

---

<a name="known-bugs-issues"></a>

## Known Bugs/Issues

* Extension has not been tested in a clustered/load-balanced database
  environment, and could have subtle/spurious issues.
* No handling of Discourse username changes.
* No handling of Discourse user deletion or anonymization.
* Logout handling is not fully symmetric:  local Discourse logouts cannot
  be propagated to MediaWiki, due to limitations in Discourse.
* See [Cautions about Discourse `user_logged_out` events](#cautions-about-discourse-user_logged_out-events).
* Discourse does not (yet?) emit `user` webhook events in all the situations
  in which it could/should.  See
  https://meta.discourse.org/t/missing-webhook-user-events-by-design-or-oversight/273579
* See `TODO` comments in the source code.

---

<a name="license"></a>

## License

This work is licensed under GPL 3.0 (or any later version).

`SPDX-License-Identifier: GPL-3.0-or-later`

Copyright 2024 Matt Marjanovic
