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

use Throwable;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\SessionManager;
use MWException;
use PluggableAuth;
use PluggableAuthLogin;
use Profiler;
use RequestContext;
use SpecialPage;
use Title;
use User;
use WebRequest;
use WebResponse;

use HTMLForm;
use OutputPage;
use Skin;


class AuthenticationPlugin extends PluggableAuth {

  /**
   * Key for state we save in AuthManager's authentication session data
   */
  public const STATE_SESSION_KEY = 'DiscourseSsoConsumerState';

  // TODO(maddog) In PHP-8.1, use a pure enumeration.
  public const PROBE_QUIET = 'quiet';
  public const PROBE_NOISY = 'noisy';

  /**
   * @var WebRequest $request - the request this instance is responding to
   */
  private $webRequest;

  /**
   * @var WebResponse $request - the response this instance is creating
   */
  private $webResponse;


  public function __construct() {
    $this->webRequest = RequestContext::getMain()->getRequest();
    $this->webResponse = $this->webRequest->response();
  }


  /**
   * Implement PluggableAuth::authenticate() interface.
   *
   * @param int &$id
   * @param string &$username
   * @param string &$realname
   * @param string &$email
   * @param string &$errorMessage
   *
   * @return bool true if user is authenticated, false otherwise
   */
  public function authenticate( &$id, &$username, &$realname, &$email,
                                &$errorMessage ) {
    if ( !Config::config()['Sso']['Enable'] ) {
      $errorMessage = "DiscourseSsoConsumer 'Sso' is not enabled/configured.";
      return false;
    }
    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    try {
      $state = $authManager->getAuthenticationSessionData(
        self::STATE_SESSION_KEY, null );

      // A little state machine:
      // TODO(maddog)  Need to either clear or ignore lingering 'credentials' state...
      if ( $state === null ) {
        // There are three ways we end up here:
        //  1) User hit "Log in" button explicitly.
        //  2) AuthRelogin probe, to try to keep user logged-in
        //  3) AuthLogin naive probe, to see if user happens to be logged-in
        //
        // For (1), we should show failed state, error msg, etc., to notify
        // the user of the results of their action.
        //
        // For (2)... ?  Probably good to flash a "Re-login failed" message,
        // to let them know that they are no longer logged in.  (Perhaps, an
        // ephemeral notification in the corner?)
        //
        // For (3), all failures should be silent --- the user did not
        // explicitly ask for a login and may just be anonymous, so pretend
        // nothing happened.
        //
        // TODO(maddog) Implement appropriate notifications.

        $cookie = Sso::getAuthCookie( $this->webRequest );
        $probing =
            ( $cookie === Sso::AUTH_COOKIE_PROBING_QUIET ) ? self::PROBE_QUIET :
            ( ( $cookie === Sso::AUTH_COOKIE_PROBING_NOISY ) ?
              self::PROBE_NOISY : null );

        // Pre-emptively set our marker as failure.  If authentication fails
        // explicitly and/or is incomplete (whether due to an error or due to
        // user declining to complete authentication), we do not want the user
        // stuck returning to Discourse to keep trying.  (They can always hit
        // the "Log in" button if they really want to try again.)
        Sso::setAuthCookie( $this->webResponse, Sso::AUTH_COOKIE_NO_MORE );

        // Ensure this session is persisted (e.g., even if we got here without
        // session cookie already set, otherwise our abrupt exit will not
        // persist the session cookies).
        // TODO(maddog) Is the above true and is this really necessary?
        $this->webRequest->getSession()->persist();

        // Now, start talking to Discourse.
        $this->initiateAuthentication( $probing );
        // initiateAuthentication() should never return.
        Util::unreachable();

      } elseif ( isset( $state['nonce'] ) ) {
        $wikiInfo = $this->completeAuthentication( $state['nonce'] );

        if ( $wikiInfo === null ) {
          Util::debug( 'STATE ' . var_export($state, true) );

          // If we were *not* probing, then fail purposefully.
          if ( $state['probe'] !== self::PROBE_QUIET ) {
            throw new MWException( "Authentication failed/declined/aborted." );
          }

          // Otherwise, quietly redirect back to the originally requested page.
          // (Our cookie should already be NO_MORE; we leave it that way.)
          $returnToPage = $authManager->getAuthenticationSessionData(
              PluggableAuthLogin::RETURNTOPAGE_SESSION_KEY );
          $returnToQuery = $authManager->getAuthenticationSessionData(
              PluggableAuthLogin::RETURNTOQUERY_SESSION_KEY );

          $returnToTitle = Title::newFromTextThrow( $returnToPage );
          $redirectUrl = $returnToTitle->getFullUrlForRedirect( $returnToQuery );

          Util::debug( 'RETURNTOPAGE:  ' . $returnToPage );
          Util::debug( 'RETURNTOQUERY:  ' . $returnToQuery );
          Util::debug( 'REDIRECTURL:  ' . $redirectUrl );

          // TODO(maddog) Take a look at LoginHelper::showReturnToPage().
          header( 'Location: ' . $redirectUrl );
          exit( 0 );
        }

        // Remember that authentication was a success.
        Sso::setAuthCookie( $this->webResponse, Sso::AUTH_COOKIE_DESIRED );

        // NB: completeAuthentication() should return a valid $wikiInfo, or null.
        // @phan-suppress-next-line PhanRedundantCondition
        Util::insist( is_array( $wikiInfo ) );
        // Set return values and return success.
        $id = $wikiInfo['id'];
        $username = $wikiInfo['username'];
        $realname = $wikiInfo['realname'];
        $email = $wikiInfo['email'];
        $errorMessage = null;

        return true;
      }
      // Else... something has gone wrong.
      Util::debug( 'Unexpected state:  ' . var_export( $state, true ) );
      throw new MWException( 'Unknown protocol state error.' );

    } catch ( Throwable $e ) {
      // TODO(maddog)  Would the entire exception/trace be better, like this:
      //          $errorMessage = $e->__toString();
      $errorMessage = $e->getMessage();
      Util::warn( $errorMessage );
      $authManager->setAuthenticationSessionData(
        self::STATE_SESSION_KEY, [ 'error' => $errorMessage ] );
      // Since we failed now, do not try to automatically again re-auth later.
      // TODO(maddog) Is this really the best policy?
      Sso::setAuthCookie( $this->webResponse, Sso::AUTH_COOKIE_NO_MORE );
      return false;
    }
  }


  /**
   * Implement PluggableAuth::saveExtraAttributes() interface.
   *
   * @param int $wikiId
   */
  public function saveExtraAttributes( $wikiId ) {
    Util::insist( Config::config()['Sso']['Enable'] );

    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    $state = $authManager->getAuthenticationSessionData(
      self::STATE_SESSION_KEY );

    $discourseId = $state['sso_credentials']['discourse_id'];
    Util::insist( $discourseId > 0 );

    if ( !$state['wiki_info']['id'] ) {
      // If the wiki id in $state is zero, that means that we did not have
      // an id earlier in the process because a new user needed to be created.
      // The id of the new user entry has now been provided to us in $wikiId,
      // so we update our session state.
      $state['wiki_info']['id'] = $wikiId;
      $authManager->setAuthenticationSessionData(
          self::STATE_SESSION_KEY, $state );
    }
    Util::insist( $wikiId === $state['wiki_info']['id'] );

    Db::updateIdLinkage( $discourseId, $wikiId );
  }


  // TODO(maddog) Consider moving this into a consolidated HookHandler class.
  public static function onSpecialPageBeforeFormDisplay(
      string $name, HTMLForm $form ) : void {

    // TODO(maddog) There is probably a more principled was to get the name
    //              of Special:UserLogout instead of hard-coding this.
    if ( $name !== 'Userlogout' ) { return; }

    if ( !Config::config()['Logout']['OfferGlobalOptionToUser'] ) { return; }

    $form->addFields( [
        'dssoc-global-logout' => [
            'name' => 'dssoc-global-logout',
            'type' => 'check',
            'label' => 'Make it global.',
            'help' => 'Check the box to logout of all sessions on all devices, not just this one.',
                                  ],
                       ] );
    // NB: The orginal text is "Submit", which is actually quite confusing,
    //     since it is preceded by the text "Do you want to log out?" !!!
    $form->setSubmitText( 'Yes, Logout Now!' );
  }


  // TODO(maddog) Consider moving this into a consolidated HookHandler class.
  /**
   * @param Skin $skin @unused-param
   */
  public static function onBeforePageDisplay( OutputPage $output, Skin $skin ) {
    // If neither SSO Logout or "Show Global Option" is enabled,
    // then nothing to do here.
    if ( !Config::config()['Logout']['OfferGlobalOptionToUser'] &&
         !Config::config()['Logout']['ForwardToDiscourse'] ) { 
      return;
    }
    // Otherwise, suppress MediaWiki's modern "logout via API" behavior,
    // so that we actually navigate to the local logout screen.
    $output->addModules( [ 'ext.DiscourseSsoConsumer.suppressApiLogout' ] );
  }


  /**
   * Implement PluggableAuth::deauthenticate() interface.
   *
   * This is actually chained off of the UserLogoutComplete hook, which is
   * invoked by ApiLogout or SpecialUserLogout after a logout has executed.
   *
   * @param User &$user
   */
  public function deauthenticate( User &$user ) {
    Util::debug( 'deauthenticate()...' );

    // The user is explicitly logging out; we need to remember this, so that
    // we will not automatically try to reauthenticate to Discourse.
    // We do this first (before any operations that could fail) to make sure
    // that it happens.
    Sso::setAuthCookie( $this->webResponse, Sso::AUTH_COOKIE_NO_MORE );

    $context = RequestContext::getMain();
    $request = $context->getRequest();
    $globalLogout = $request->getBool( 'dssoc-global-logout' );

    if ( $globalLogout ) {
      // Invalidate all of the user's wiki sessions.
      SessionManager::singleton()->invalidateSessionsForUser( $user );

      // Maybe send a synchronous logout request the Discourse log_out API.
      if ( Config::config()['Logout']['ForwardToDiscourse'] ) {
        $wikiId = $user->getId();
        $discourseId = Db::lookupDiscourseIdByWikiId( $wikiId );
        if ( $discourseId !== null ) {
          DiscourseApi::logoutUser( $discourseId );
        } else {
          // It is possible (especially if $wgPluggableAuth_EnableLocalLogin is
          // true) that $user was not ever authenticated by Discourse, and thus
          // has no linked discourse-id.  So, we just log this case and move on.
          Util::debug(
              "User '{$user->getName()}' (wiki-id {$wikiId}) not linked to " .
              "any discourse-id; skipping Discourse logout." );
        }
      }
    }

    // If logout via SSO is not enabled, we are finished here.
    if ( !Config::config()['Logout']['ForwardToDiscourse'] ) {
      return;
    }

    // NB: It appears to be a-ok for Discourse to receive the SSO logout
    //     request after it has already globally logged-out a user via the
    //     API request.

    Util::debug( 'Redirecting logout back to Discourse SSO...' );

    // At this point, we should be here because the UserLogoutComplete hook
    // has been invoked.  We *should* be in the SpecialUserLogout flow (and
    // not the ApiLogout flow) --- if not, the redirect to Discourse is not
    // going to work very well.
    //
    //   - User::logout() has already been executed.
    //   - The "you are logged out" page has been constructed in the
    //     current response's $output OutputPage, using the 'returnto'
    //     and 'returntoquery' parameters from the request (via
    //     OutputPage::returnToMain()).
    //
    // We just need to tell Discourse to return back to where we already are,
    // which should be Special:UserLogout.  This only works for us because
    // Special:UserLogout will just go ahead and display the "you are logged
    // out" success page if the requestor is already logged out.  We do need
    // to preserve the returnto/returntoquery parameters, to recreate the
    // state we are in now.

    $title = $context->getTitle();
    Util::insist( $title !== null );
    // We might be the only consumer of the 'redirectparams' parameter, yet,
    // there it is, ready for us to use!
    $redirectparams = $request->getVal( 'redirectparams', '' );
    // Don't worry, phan:  we provided an empty string as the default value.
    '@phan-var string $redirectparams';
    $returnAddress = $title->getFullURL( $redirectparams );

    // (Note that we don't care about saving the nonce for this request,
    // because the logout request will not produce a response that we need
    // to validate.)
    Sso::redirectToSsoEndpointAndExit( Util::generateRandomNonce(),
                                       $returnAddress,
                                       false /*isProbe*/,
                                       true /*isLogout*/ );
  }


  /**
   * Implement PluggableAuth's PluggableAuthPopulateGroups hook.
   *
   * If a GroupMaps has been configured, (re)populates the group membership
   * of $user according to the 'groups', 'is_admin', and 'is_moderator'
   * elements retrieved from the Discourse SSO credentials.
   *
   * @param User $user
   *
   */
  public static function onPluggableAuthPopulateGroups( User $user ) {
    Util::debug(
      "PopulateGroups hook for user #{$user->getId()} '{$user->getName()}'..." );
    Util::insist( Config::config()['Sso']['Enable'] );

    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    $state = $authManager->getAuthenticationSessionData(
      self::STATE_SESSION_KEY );
    // TODO(maddog) (In PA>=5.7?...) if more than one auth-plugin is enabled,
    //              it may be possible to have more than one source of
    //              "autocreated" users... in which case, is it possible
    //              for this hook to be called even if DSSO is not the
    //              source of the user?  (In which case, we should quietly
    //              return if no DSSO session data is available.)
    Util::insist( $state['wiki_info']['id'] === $user->getId() );
    $credentials = $state['sso_credentials'];

    Core::populateGroupsForUser( $user, $credentials );
  }


  /**
   * Initiate the SSO handshake, by redirecting the user's browser to the
   * Discourse SSO endpoint.  The SSO request will contain a random nonce
   * and return URL, and will be signed using the shared-secret.
   *
   * The Discourse server is expected to subsequently redirect the browser
   * back to the MediaWiki instance, with a request bearing the results of
   * the SSO handshake.
   *
   * @param ?string $probing If non-null, request only current authentication
   *                         state, and avoid sending user to a login dialog.
   *                         Non-null value should be one of the PROBE_*
   *                         constants, to indicate how an eventual auth
   *                         failure should be handled.
   *
   * This method does not return.
   */
  // TODO(maddog) In PHP-8.1, give this the return type "never".
  // TODO(maddog) Tighter typing of $probing is possible?
  //              E.g.: in PHP-8.1, use a backed enumeration.
  private function initiateAuthentication( ?string $probing ) : void {
    Util::debug( "Initiating authentication..." );

    $nonce = Util::generateRandomNonce();

    // We will tell Discourse to send its response (via browser redirection)
    // to the magic "PluggableAuthLogin" page provided by PluggableAuth.
    $returnAddress = SpecialPage::getTitleFor(
      'PluggableAuthLogin' )->getFullURL();

    // Update session state (saving nonce for the next phase).
    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    $authManager->setAuthenticationSessionData(
      self::STATE_SESSION_KEY, [ 'nonce' => $nonce,
                                 'probe' => $probing ] );

    Sso::redirectToSsoEndpointAndExit( $nonce,
                                       $returnAddress,
                                       $probing !== null,
                                       false /*isLogout*/ );
  }


  /**
   * Complete the SSO handshake, processing the response from the
   * Discourse SSO endpoint.  If authentication succeeded, this returns
   * an array bearing the wiki id, username, realname, and email address
   * of the authenticated user.  The wiki id may be null if the user does
   * not yet exist in MediaWiki.
   *
   * @param string $originalNonce the nonce used to initiate the handshake
   *
   * @return ?array if authentication was successful, null if not
   *
   * @throws MWException if authentication fails unexpectedly
   */
  private function completeAuthentication( string $originalNonce ) : ?array {
    Util::debug( "Completing authentication..." );

    $ssoCredentials = Sso::validateAndUnpackCredentials( $this->webRequest,
                                                         $originalNonce );
    if ( $ssoCredentials === null ) {
      return null;
    }

    Util::debug(
        'Received credentials: ' . var_export( $ssoCredentials, true ) );

    // We arrived here via a GET request, and the TransactionProfiler is
    // set up by MediaWiki::main() to believe that all GET requests are
    // "safe" and should never write to the DB.  But, from this point forward
    // we very much do write to the DB (grabbing a lock, creating a user,
    // changing group assignments, etc).  So, we redefine the 'writes' limit
    // here to get profiler to stop spamming the logfiles with complaints.
    // Unfortunately there is no TransactionProfiler::getExpectations()
    // method, so we grab the 'GET' limits from the config and assume that
    // MediaWiki::main() is still doing that, too.
    global $wgTrxProfilerLimits;
    $trxProfiler = Profiler::instance()->getTransactionProfiler();
    $loweredExpectations = $wgTrxProfilerLimits['GET'];
    unset( $loweredExpectations['writes'] );
    $trxProfiler->redefineExpectations( $loweredExpectations, __METHOD__ );

    // We know we are going to working on a particular discourse-id, so grab
    // the lock on it (and reset any stale REPEATABLE-READ transaction state).
    Db::acquireLockOnDiscourseId( $ssoCredentials['discourse_id'] );

    // Lookup the discourse user in our registry.
    // If the discourse id is known to us, we return info for an existing
    // wiki user (with updates from the credentials name/email).
    // If the discourse id is not known to us, we need to either link
    // to an existing MW user, or create a brand-new one.
    $wikiInfo =
        Core::makeUpdatedInfoForAlreadyLinkedUser( $ssoCredentials ) ??
        Core::handleUnknownUser( $ssoCredentials );

    // At this point, we should have SSO credentials describing the user,
    // as well as complementary wiki information.
    // @phan-suppress-next-line PhanRedundantCondition
    Util::insist( is_array( $ssoCredentials ) );
    // @phan-suppress-next-line PhanRedundantCondition
    Util::insist( is_array( $wikiInfo ) );

    // Update state.
    $state = [ 'sso_credentials' => $ssoCredentials,
               'wiki_info' => $wikiInfo ];
    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    $authManager->setAuthenticationSessionData(
      self::STATE_SESSION_KEY, $state );

    return $wikiInfo;
  }

}
