<?php
/**
 * This file is part of DiscourseSsoConsumer.
 *
 * Copyright 2023 Matt Marjanovic
 *
 * DiscourseSsoConsumer is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as published
 * by the Free Software Foundation; either version 3 of the License, or any
 * later version.
 *
 * DiscourseSsoConsumer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with DiscourseSsoConsumer.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\DiscourseSsoConsumer;

use ExtensionRegistry;
use MediaWiki;
use OutputPage;
use SpecialPage;
use Title;
use User;
use WebRequest;


class AutoRelogin { // TODO(maddog) implements BeforeInitializeHook {

  /**
   * Magic URL parameter used when testing if the browser allows our cookies.
   */
  private const COOKIE_TEST_PARAM = 'DssocNoCookies';

  // TODO(maddog) Consider moving this into a consolidated HookHandler class.
  /**
   * Implement BeforeInitialize hook.
   *
   * Following the example of PluggableAuth's use of this hook for its
   * 'EnableAutoLogin' feature, we use this hook to implement our
   * 'AutoRelogin' feature.  Very early in page processing, we check
   * if the user needs to reauthenticate via DiscourseSSO (e.g., because
   * their MW session expired).
   *
   * @param Title $title
   * @param Null $literallyUnused @unused-param
   * @param OutputPage $out
   * @param User $user @unused-param
   * @param WebRequest $request
   * @param MediaWiki $mw @unused-param
   *
   * @return void  This method does not return a value, and may not return
   *               at all in case it redirects to the login flow.
   */
  public static function onBeforeInitialize(
      $title, $literallyUnused, $out, $user, $request, $mw ) {

    // If Sso is not enabled, then nothing to do here.
    if ( !Config::config()['Sso']['Enable'] ) {
      return;
    }
    // If neither SeamlessLogin nor AutoRelogin is enabled, then we have
    // nothing to do here.
    if ( !Config::config()['Sso']['EnableSeamlessLogin'] &&
         !Config::config()['Sso']['EnableAutoRelogin'] ) {
      return;
    }

    // If:
    //  - request is a POST (and thus could never redirect to authenticate), OR
    //  - user is already logged in, OR
    //  - the requested page is already in the login flow
    // then nothing to do.
    //
    // NB:  Checking for POST also (correctly) skips auth-probing for
    //      incoming Discourse webhook requests.  (Also, note that only
    //      index.php requests will invoke this onBeforeInitialize() hook,
    //      so API requests (api.php) do not come through here.)
    if ( $request->wasPosted() ||
         !$out->getUser()->isAnon() ||
         self::isLoginSpecialPage( $title ) ) {
      Util::debug(
          "Skipping probe-only authentication (POST/logged-in/logging-in)" );
      return;
    }
    // TODO(maddog) Will anyone need a configurable pages-to-skip list?
    //              (E.g., in addition to the login-related pages)

    $cookie = Sso::getAuthCookie( $request );

    // If an earlier probe failed or the user had explicitly logged out,
    // then nothing to do.
    if ( $cookie === Sso::AUTH_COOKIE_NO_MORE ) {
      Util::debug(
          "Skipping probe-only authentication (previous fail/logout)" );
      return;
    }
    // TODO(maddog) This implies that if:
    //   - user explicitly logs out of wiki
    //   - user logs out of discourse
    //   - user logs back in to discourse
    //   - user visits wiki
    //  then they will never auto-login to wiki again.
    //  Is this behavior acceptable?
    //
    //  ...this is basically what happens if Logout/ForwardToDiscourse is true,
    //  and the user goes back and re-logs-in to Discourse before Wiki.
    //  We would *want* SeamlessLogin to kick in when they return to wiki!
    //
    //  ...MAYBE:  via webhook, allow discourse to notify wiki that a user has
    //     logged-in again (at some point), so that a NO_MORE cookike should be
    //     ignore at least once?  BUT, no way for wiki to know that a random
    //     non-auth'ed browser is being used by any particular user!

    if ( $cookie === Sso::AUTH_COOKIE_DESIRED ) {
      // We know that user had been logged-in before (and that cookies work).
      if ( !Config::config()['Sso']['EnableAutoRelogin'] ) {
        // No AutoRelogin?  Then we will not try to login again.
        return;
      }
      // Attempt probe-only authentication to Discourse, allowing notification
      // on failure.
      self::redirectToUserlogin( $request, $title, $out, true/*noisy*/ );
      Util::unreachable();
    }

    // Bail if SeamlessLogin is not enabled, because the rest of this code
    // exists only to serve SeamlessLogin.
    if ( !Config::config()['Sso']['EnableSeamlessLogin'] ) {
      return;
    }

    if ( $cookie !== null ) {
      // Having any cookie at this point tells us that cookies are available,
      // so we can try SeamlessLogin probing.  However, the only cookie that
      // we actually expect here is the PRESENT cookie.
      if ( $cookie !== Sso::AUTH_COOKIE_PRESENT ) {
        Util::debug( "Unexpected cookie value: {$cookie}" );
      }
      // User is not known to have been logged-in before, but cookies do work,
      // so attempt to authenticate quietly (no notifications if probe-only
      // authentication request to Discourse fails).
      self::redirectToUserlogin( $request, $title, $out, false/*not noisy*/ );
      Util::unreachable();
    }

    $cookieTest = $request->getVal( self::COOKIE_TEST_PARAM );
    // Have we just now discovered that cookies are not working?
    // Or, have we already discovered that cookies are not working?
    // (We ask this *after* all the prior checks, because the prior checks
    // give us an opportunity to bail and let the NOCOOKIES status be forgotten.)
    if ( $cookieTest !== null ) {
      // Nope, cookies don't work for this client.
      // Nothing more to do since login is not even possible without cookies.
      Util::debug( "No cookies available.  {$cookieTest}" );
      return;
      // TODO(maddog) This mechanism causes every page load for a cookie-less
      //              client to be doubled:  initial GET is always redirected
      //              to a URL with the cookie-test parameter appended.
      //
      //              It would be nice to have a way to propagate this knowledge
      //              (that cookies do not work) forward --- but, of course, we
      //              cannot use cookies to do that.
      //
      //              We could try to force the cookie-test param into the URL's
      //              for links on the page... but:
      //               a) there does not appear to be any mechanism that would
      //                  ensure that this happens for all the links returned
      //                  to the client (or even most);
      //               b) rendered links are cached with the page, so we would
      //                  have to be careful add the "no-cookie" state into the
      //                  parser cache key somehow(*), which would also double
      //                  the cache requirement (nearly duplicate renderings for
      //                  the cookie vs no-cookie pages).
      //                    [*- see onParserOptionsRegister hook]
      //
      //              Maybe it would be possible to pass no-cookies as a
      //              prefix component in the URL *path*, and propagate it by
      //              switching up the path during a request execution?  That
      //              would only work if the parser cache does not include the
      //              entire path in cached links....
      //
      //              Maybe a whole different server domain?  That also would
      //              only work if the entire URL is not included in cached
      //              links.
    }

    // Time to check if cookies even work for this client.
    self::redirectForCookieTest( $request, $out );
    Util::unreachable();
  }


  private static function isLoginSpecialPage( Title $title ) : bool {
    $loginPages = ExtensionRegistry::getInstance()->
        getAttribute( 'PluggableAuthLoginSpecialPages' );
    foreach ( $loginPages as $page ) {
      if ( $title->isSpecial( $page ) ) {
        Util::debug( "We are in a login flow: {$page}" );
        return true;
      }
    }
    return false;
  }


  /**
   *
   * This method does not return.
   */
  // TODO(maddog) In PHP-8.1, give this the return type "never".
  private static function redirectToUserlogin(
      WebRequest $request, Title $originalTitle, OutputPage $out,
      bool $noisy ) : void {
    Util::debug( "Redirecting to probe-only " .
                 ($noisy ? 're-' : '') . "authentication..." );

    Sso::setAuthCookie(
        $request->response(),
        $noisy ?
        Sso::AUTH_COOKIE_PROBING_NOISY : Sso::AUTH_COOKIE_PROBING_QUIET );

    // If our cookie-test parameter is in the query string, remove it.
    // (If we got this far, then we have already decided that cookies are ok.)
    $query = $request->appendQueryArray( [ self::COOKIE_TEST_PARAM => null ] );
    // NB:  appendQueryArray() will (quietly, undocumented-ly) strip out any
    //      "title=" parameter that might happen to be in the existing query
    //      string for the request.  That happens to be OK in our use-case here,
    //      since we are passing the page title independently via 'returnto',
    //      when it all gets assembled back together by PluggableAuth (or,
    //      by us), it all works out.  (E.g., if we leave the "title=" in there,
    //      it ends up getting duplicated!)

    $redirectUrl = SpecialPage::getTitleFor( 'Userlogin' )->getFullURL(
        [ 'returnto' => $originalTitle,
          'returntoquery' => $query, ] );
    // Ensure a session (e.g., cookies) is persisted.
    $request->getSession()->persist(); // TODO(maddog) Is this really necessary?
    $out->redirect( $redirectUrl );
    $out->output();
    exit( 0 );
  }


  /**
   *
   * This method does not return.
   */
  // TODO(maddog) In PHP-8.1, give this the return type "never".
  private static function redirectForCookieTest(
      WebRequest $request, OutputPage $out ) : void {
    // Try to set a placeholder cookie.
    Sso::setAuthCookie( $request->response(), Sso::AUTH_COOKIE_PRESENT );

    // Reconstruct the original request URL, with the cookie-check parameter
    // added to the query parameters.
    //
    // NB: Do *not* use WebRequest::appendQueryValue() here --- it always
    //     strips out any "title=" parameter... and here that parameter may
    //     be the only indication of the page being linked to.
    $redirectUrl = wfAppendQuery( $request->getFullRequestURL(),
                                  [ self::COOKIE_TEST_PARAM => 1 ] );

    Util::debug( "Redirecting to test for cookies... {$redirectUrl}" );

    // Ensure a session (e.g., cookies) is persisted.
    $request->getSession()->persist(); // TODO(maddog) Is this really necessary?
    $out->redirect( $redirectUrl );
    $out->output();
    exit( 0 );
  }
}
