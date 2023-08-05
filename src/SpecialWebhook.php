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

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\PrimaryAuthenticationProvider;
use Exception;
use MediaWiki\MediaWikiServices;
use MWException;
use MWTimestamp;
use PluggableAuthLogin;
use PluggableAuthPrimaryAuthenticationProvider;
use MediaWiki\Session\SessionManager;
use SpecialPage;
use Status;
use User;
use WebRequest;


// TODO(maddog)  Unrelated to any of this:  try to get the "failed SSO
//               login stays on discourse and does not return to where
//               it came from!" Discourse bug fixed.


// Notes on Discourse Events
//
// There doesn't seem to be an authoritative list of the possible events
// within the 'user' event type.  ("Use the source, Luke....")
//
// Between "config/initializers/012-web_hook_events.rb" and "app/models/user.rb",
// it appears that the possible events are:
//
//  * user_created
//    - account is freshly created
//  * user_confirmed_email
//    - user has confirmed email address (or, admin has "activated account")
//  * user_approved
//    - admin has approved the account (if required by site config)
//  * user_logged_in
//    - user has logged in
//  * user_updated
//    - user data has changed (but not all changes trigger this!)
//         - anonymized
//  * user_logged_out
//    - user has logged out, or has been logged out (e.g., via suspension)
//  * user_destroyed
//    - account has been deleted
//
//
// As of Discourse 3.1.0.beta5, via testing by hand:
//
// Action                       | Resulting Webhook(s)
// =============================|=============================================
// user created                 | user/user_created
// -----------------------------|---------------------------------------------
// email confirmed,             | user/user_confirmed_email
// account activated (by admin) |
// -----------------------------|---------------------------------------------
// logs in                      | user/user_logged_in
// -----------------------------|---------------------------------------------
// real name is changed         | user/user_updated
// -----------------------------|---------------------------------------------
// profile picture changed      | user/user_updated
// -----------------------------|---------------------------------------------
// logged out by admin,         | user/user_logged_out
//   "strict log out", or not   |
// -----------------------------|---------------------------------------------
// logged out via "Log out all" | NO EVENT!
//   "strict log out", or not   |
// -----------------------------|---------------------------------------------
// logs out via logout button,  | user/user_logged_out
//   "strict log out" enabled   |
// -----------------------------|---------------------------------------------
// logged out via SSO request,  | user/user_logged_out
//   "strict log out" enabled   |
// -----------------------------|---------------------------------------------
// logs out via logout button,  | no event
//   not "strict log out"       |
// -----------------------------|---------------------------------------------
// logged out via SSO request   | no event
//   not "strict log out"       |
// -----------------------------|---------------------------------------------
// added to a regular group     | group_user/user_added_to_group
// -----------------------------|---------------------------------------------
// removed from a regular group | group_user/user_removed_from_group
// -----------------------------|---------------------------------------------
// trust level bumped up        | user_promoted/user_promoted
//                              | group_user/user_added_to_group
// -----------------------------|---------------------------------------------
// trust level bumped down      | group/group_updated
//                              |  - for every autom. group!
//                              |    (and, 2x for 'moderators'!?)
// -----------------------------|---------------------------------------------
// grant moderation             | group/group_updated
//                              |  - admins, moderators, staff
//                              |    - no actual changes to admins!
//                              |      - why was an update sent?
//                              |    - no changes to moderators or staff!
//                              |      - but user_count should have increased!
//                              | group_user/user_added_to_group
//                              |  - added to moderators
//                              |  - added to staff
// -----------------------------|---------------------------------------------
// revoke moderation            | group/group_updated
//                              |  - for each of admins, moderators, staff
//                              |    - no actual changes to admins!
//                              |      - why was an update sent?
//                              |    - no changes to moderators or staff!
//                              |      - but user_count should have decreased!
//                              | *no* user_removed_from_group sent!!!
// -----------------------------|---------------------------------------------
// grant admin                  | group/group_updated
//                              |  - admins, moderators, staff
//                              |    - no actual changes to moderators!
//                              |      - why was an update sent?
//                              |    - no changes to admins or staff!
//                              |      - but user_count should have increased!
//                              | group_user/user_added_to_group
//                              |  - added to admins
//                              |  - added to staff
// -----------------------------|---------------------------------------------
// revoke admin                 | group/group_updated
//                              |  - for each of admins, moderators, staff
//                              |    - no actual changes to moderators!
//                              |      - why was an update sent?
//                              |    - no changes to admins or staff!
//                              |      - but user_count should have decreased!
//                              | *no* user_removed_from_group sent!!!
// -----------------------------|---------------------------------------------
// suspended                    | user/user_logged_out
//                              |    - suspended_reason, suspended_till
//                              |        in user record
// -----------------------------|---------------------------------------------
// unsuspended                  | no event
// -----------------------------|---------------------------------------------
// silenced                     | no event
// -----------------------------|---------------------------------------------
// unsilenced                   | no event
// -----------------------------|---------------------------------------------
// account deactivated          | no event
// -----------------------------|---------------------------------------------
// account anonymized           | user/user_updated
//                              |  - but original email address still there!
//                              | user/user_updated, again!
//                              |  - now email address is updated
//                              |  - back-to-back webhook requests cause a
//                              |    race condition (second hook start to
//                              |    process before first has committed),
//                              |    which is detected but causes the second
//                              |    request to fault and be discarded!
//                              |
//                              | user *is* logged out
//                              | - BUT, no user_logged_out event!
// -----------------------------|---------------------------------------------
// account destroyed            | user/user_destroyed
//                              |  - nothing in contents indicates that user
//                              |    account has been destroyed/altered
//
//
// Possible race condition scenarios:
//
// action/activity           | event(s)            | status
// --------------------------|---------------------|------------------------
// login to discourse        | user_logged_in      | no race
// --------------------------|---------------------|------------------------
// login to mediawiki, with  | sso, user_logged_in | race on first
// no prior discourse login  |                     |  wiki login flow
// --------------------------|---------------------|------------------------
// login to mediawiki, after | sso                 | no race
// prior discourse login     |                     |
// --------------------------|---------------------|------------------------
// anonymize user            | user_updated x2,    | potential race
//                           |  sometimes!         |
//
// (We prevent all of these via Db::acquireLockOnDiscourseId().)
//


class SpecialWebhook extends SpecialPage {

  /**
   * Name of our webhook endpoint Special page.
   */
  public const PAGE_NAME = 'DiscourseSsoConsumerWebhook';

  /**
   * Label of configuration parameter subarray with webhook parameters
   */
  public const SUBCONFIG_PARAM = 'Webhook';

  /**
   * List of all the Discourse user events that we are aware of.
   *
   * Events not listed here will be logged and otherwise ignored.
   */
  public const KNOWN_USER_EVENTS = [
      'user_created',
      'user_confirmed_email',
      'user_approved',
      'user_logged_in',
      'user_updated',
      'user_logged_out',
      'user_destroyed',
                                    ];

  /**
   * @var array $config:  the subarray of webhook parameters
   */
  private $config;

  /**
   * @var object $postedJson:  decoded JSON from POST request
   */
  private $postedJson;

  /**
   * @var MWTimestamp $now:  timestamp of recording when execute() is called
   */
  private $now;


  public function __construct() {
    parent::__construct( self::PAGE_NAME,
                         '', // $restriction = none
                         false, // $listed = not
                         );
    // NB: We assume Core has validated our configuration.
    $this->config = Config::config()[self::SUBCONFIG_PARAM];
    // (But we don't completely trust ourselves.)
    Util::insist( is_array($this->config) );
  }


  public function doesWrites() {
    return true;
  }


  /**
   * Extract a required header from a WebRequest.
   *
   * @param WebRequest $request the request to inspect
   * @param string $header the name of the header
   *
   * @return string the value of the header
   *
   * @throws MWException if the request has no such header
   */
  private static function requireHeader(
      WebRequest $request, string $header ) : string {
    $result = $request->getHeader( $header );
    '@phan-var string|false $result'; // NB: no GETHEADER_LIST flag set.
    if ( !$result ) {
      throw new MWException( "Empty/missing {$header} header" );
    }
    return $result;
  }


  /**
   * Implement SpecialPage::execute() interface.
   *
   * @param string|null $subPage sub-page fragement from request URL
   *
   * @return void
   */
  public function execute( $subPage ) {
    // TODO(maddog) It would be better to use some timestamp machinery that
    //              relates to $_SERVER['REQUEST_TIME_FLOAT'] --- e.g.,
    //              WebRequest has $requestTime, but doesn't provide public
    //              access to it.  Until something better comes along....
    $this->now = MWTimestamp::getInstance();  // current time right now

    $output = $this->getOutput();
    $output->disable();
    $request = $this->getRequest();
    $response = $request->response();

    // Until we have established that the request is sincere, respond
    // to any problems with a blank stare.
    try {
      $this->validateRequest( $subPage );
    } catch ( Exception $e ) {
      Util::warn( $e->getMessage() );
      http_response_code( 500 ); // "Internal Server Error"
      exit( 1 );
    }

    // Ok, now we know we are talking to someone we trust, mostly.  We can be
    // a bit more talkative if anything goes wrong.
    try {
      // Switch on event type.
      $eventType = self::requireHeader( $request, 'X-Discourse-Event-Type' );
      $eventName = self::requireHeader( $request, 'X-Discourse-Event' );
      $eventId = (int) self::requireHeader( $request, 'X-Discourse-Event-Id' );

      $responseText = $this->handleEvent( $eventType, $eventName, $eventId );

      $response->header( 'Content-Type: text/plain; charset=UTF-8' );
      print $responseText;
      Util::debug( "RESPONSE: {$responseText}" );

      // TODO(maddog) Do we need to do anything about discarding sessions when
      //              the webhook is received?  E.g.,
      //                 $request->getSession()->unpersist();
      //              Does anything we do here even create a session?  Would
      //              this inadvertently logout any user that for some reason
      //              navigated to webhook special page?
      //              (Ha!  Serves them right!)
    } catch ( Exception $e ) {
      Util::warn( $e->getMessage() );
      http_response_code( 500 ); // "Internal Server Error"
      print "Internal Server Error\n\n{$e}\n";
      exit( 1 );
    }
  }


  /**
   * Validate (as best we can) that this request is a legitimate Discourse
   * webhook request coming from a trusted source.
   *
   * @param string|null $subPage sub-page fragement from request URL
   *
   * @return void This function returns no value.
   *
   * @throws MWException if validation fails.
   */
  private function validateRequest( ?string $subPage ) {
    if ( !$this->config['Enable'] ) {
      // We are not expecting anyone to call us.
      throw new MWException( 'Webhook is not configured/enabled.' );
    }

    if ( $subPage !== null ) {
      throw new MWException(
          'Subpage is not null: ' . var_export( $subPage, true ) );
    }

    $request = $this->getRequest();

    // Typical request headers from discourse:
    //
    // Content-Type: application/json
    // Host: wiki.sunshinepps.org
    // User-Agent: Discourse/2.9.0.beta9
    // X-Discourse-Instance: https://talk.sunshinepps.org
    // X-Discourse-Event-Id: 7
    // X-Discourse-Event-Type: user
    // X-Discourse-Event: user_logged_in
    // X-Discourse-Event-Signature: sha256=64cdf1b936c178e6502676f6bfd0ca80cd7f49bf812e9354e142eb68530731db
    //
    // The webhook protocol is subject to replay attacks, because, although
    // the payload is signed, the payload contains no information about
    // when the request was sent.  (E.g., the event id is in the unsigned
    // headers, there is no timestamp in the payload or headers, etc.)
    //
    // This is bad, since we update group assignments in response to these
    // requests.  An attacker could replay an earlier update to reassign a
    // user to a group from which they had been removed!
    //
    // So... at the very least, we validate the source IP address.
    if ( !in_array($request->getIP(), $this->config['AllowedIpList'],
                   true/*strict*/) ) {
      throw new MWException(
          "Sender IP {$request->getIP()} not in allowed list" );
    }

    // Validate the request (check headers/signature/etc).
    $postSignature = self::requireHeader( $request,
                                          'X-Discourse-Event-Signature' );
    if ( substr( $postSignature, 0, 7 ) !== 'sha256=' ) {
      throw new MWException( 'Signature is not sha256.' );
    }
    $postedSha256 = substr( $postSignature, 7 );

    // Get the POST data... but don't do anything with it (except validate
    // its signature) until after we validate its signature.
    $postedData = $request->getRawPostString();

    $sharedSecret = $this->config['SharedSecret'];
    Util::insist( is_string( $sharedSecret ) );
    $computedSha256 = hash_hmac( 'sha256', $postedData, $sharedSecret );
    if ( $postedSha256 !== $computedSha256 ) {
      throw new MWException(
          "Signature mismatch:  '{$postedSha256}' versus '{$computedSha256}'" );
    }
  }


  /**
   * Handle a Discourse event.
   *
   * @param string $eventType General event class, from request header
   * @param string $eventName Specific event type, from request header
   *
   * @return string containing literal contents for response body
   * @throws MWException if event is not handled successfully
   */
  private function handleEvent(
      string $eventType, string $eventName, int $eventId ) : string {
    switch ( $eventType ) {
      case 'ping':
        return $this->handlePingEvent( $eventName );
      case 'user':
        return $this->handleUserEvent( $eventName, $eventId );
      default:
        return "NON-HANDLED EVENT TYPE '{$eventType}'\n";
    }
  }


  /**
   * Handle Discourse 'ping' event class.
   *
   * $eventName must be 'ping'.  Responds with 'PONG' and re-encoded JSON
   * of the request body.
   *
   * @param string $eventName Specific event type, from request header
   *
   * @return string containing literal contents for response body
   * @throws MWException if event is not handled successfully
   */
  private function handlePingEvent( string $eventName ) : string {
    if ( $eventName !== 'ping' ) {
      throw new MWException(
          "Expected event to be 'ping', not '{$eventName}'." );
    }
    $reencoded = Util::encodeObjectAsJson( $this->getPostedJson() );
    return "PONG\n{$reencoded}\n";
  }


  /**
   * Handle Discourse 'user' event class.
   *
   * @param string $eventName Specific event type, from request header
   * @param int $eventId ID of the event request, from request header
   *
   * @return string containing literal contents for response body
   * @throws MWException if event is not handled successfully
   */
  private function handleUserEvent( string $eventName, int $eventId ) : string {
    // NB: We have 'user_created' in the extension's default ['IgnoredEvents']
    //     parameter, because a user can be destroyed after creation by simply
    //     being deactivated by an admin.  It is better to wait until email
    //     confirmation before linking/creating a wiki-user.
    if ( in_array( $eventName, $this->config['IgnoredEvents'],
                   true/*strict*/ ) ) {
      Util::debug( "Skipping ignored user event '{$eventName}'" );
      return "IGNORED USER EVENT '{$eventName}'\n";
    }
    if ( !in_array( $eventName, self::KNOWN_USER_EVENTS, true/*strict*/ ) ) {
      Util::debug( "Skipping unrecognized user event '{$eventName}'" );
      return "UNRECOGNIZED USER EVENT '{$eventName}'\n";
    }

    $discourseUser = $this->getPostedJson()->user;

    // Synthesize SSO-like credentials.
    $newCredentials = self::makeCredentialsFromUserRecord( $discourseUser );

    // TODO(maddog) If a *username* is changed/edited, a user_updated event is
    //              emitted.  We should provide a hook on update events so that,
    //              e.g., admin can write a function to handle username change
    //              by calling into Renameuser extension or something.

    // We know we are going to working on a particular discourse-id, so grab
    // the lock on it (and reset any stale REPEATABLE-READ transaction state).
    Db::acquireLockOnDiscourseId( $newCredentials['discourse_id'] );

    // If the Discourse user is not already linked to the wiki,
    // try linking to an existing user.  At the very least, we will
    // end up with a name for a new user we need to create/link.
    $newWikiInfo =
        Core::makeUpdatedInfoForAlreadyLinkedUser( $newCredentials ) ??
        Core::handleUnknownUser( $newCredentials );

    if ( $newWikiInfo['id'] !== null ) {
      // We have an existing wiki user linked to the Discourse user; update it.
      $this->updateWikiUser( $newWikiInfo, $newCredentials );
    } elseif ( $eventName !== 'user_destroyed' ) {
      // (Don't create new wiki-user if the discourse-user was just destroyed!)
      //
      // We could not find an existing wiki user to link to, so we will try
      // to create a new wiki user.  If the wiki has been not been configured
      // to allow automatic user creation, this will fail (per the intent of
      // the site administrators).
      $newWikiInfo['id'] = $this->createNewUser( $newWikiInfo, $newCredentials);
      // If this did not yield a wiki user-id, it should have thrown.
      // We do not want to continue if we were not allowed to create
      // a new user.
      Util::insist( $newWikiInfo['id'] !== null );
    }

    if ( $newWikiInfo['id'] !== null ) {
      // TODO(maddog) Should 'user_destroyed' also cause us to
      //               - remove link to wiki user?
      //               - suspend/etc wiki user?
      if ( in_array( $eventName, [ 'user_logged_out',
                                   'user_destroyed' ], true/*strict*/ ) &&
           Config::config()['Logout']['HandleEventFromDiscourse'] ) {
        Util::debug( "Globally logging out user {$newWikiInfo['id']}..." );
        $user = User::newFromId( $newWikiInfo['id'] );
        SessionManager::singleton()->invalidateSessionsForUser( $user );
        // NB:  'user_logged_out' is a global logout, that occurs when the
        //      user is logged out of all Discourse sessions, e.g.,
        //       - if the user is suspended;
        //       - if "log out strict" is enabled for the site.
        //      Hence, we invalidate all wiki sessions, too.
        //
        //      The user's browser will still have (invalid) session cookies for
        //      both Discourse and MediaWiki, and potentially a "try to keep me
        //      logged-in" auth-cookie on MW from us.  However, on the next
        //      access to MediaWiki, those invalid cookies will be discovered
        //      and cleared out and the user will be treated as anonymous.
      }

      // If we got this far, a wiki user is linked to the Discourse user and
      // has had its account information updated, so we should store the new
      // user record, too.
      Db::updateUserRecord( $discourseUser, $eventName, $eventId, $this->now );
    }

    // Return something to Discourse that might be useful in its log of this
    // interaction.
    return "WIKI USER:\n" .
        Util::encodeObjectAsJson( (object) $newWikiInfo ) . "\n" .
        "\n" .
        "CREDENTIALS:\n" .
        Util::encodeObjectAsJson( (object) $newCredentials ) . "\n";
  }


  /**
   * Create SSO credentials from bits in a user-event "user" record.
   *
   * @param object $user the "user" object from the decoded-JSON of a
   *                     user event's payload
   *
   * @return array an array of SSO credentials
   */
  private static function makeCredentialsFromUserRecord( object $user ) : array {
    return [
        'discourse_id' => $user->id,
        'username' => $user->username,
        // NB: 'name' in the user record can be null if the Discourse user
        //     never set their name.  We'll treat that as empty string.
        'name' => $user->name ?? '',
        'email' => $user->email,
        'groups' => array_map(
            (function( object $g ) : string { return $g->name; }),
            $user->groups ),
        'is_admin' => $user->admin,
        'is_moderator' => $user->moderator,
            ];
  }


  // Notes on PluggableAuth flow:
  //
  // PluggableAuthLogin::execute()
  //  -> DSSOC-AP->authenticate()
  //      -> completeAuthentication()
  //          -> handleUnknownUser()                    **********************
  //              - returns id === null
  //                     OR id !== null
  //     - returns id == null OR id !== null
  //  if id !== null
  //    - load user from DB
  //    - run Hook-PA-PopulateGroups
  //          ----> DSSOC-Core:populateGroupsForUser()  **********************
  //  else
  //    - populate stub user object
  //  (run Hook-PA-UserAuthorization to check author)
  //  set Auth-SessionData
  //    - USERNAME_SESSION_KEY
  //    - REALNAME_SESSION_KEY
  //    - EMAIL_SESSION_KEY
  //  redirect to $returnToUrl  (from RETURNTOURL_SESSION_KEY)
  //
  //
  // AuthManager::continueAuthentication()
  //
  // (Step 2)
  //   $user = User::newFromName( $res->username, 'usable' );
  //   if ( $user->getId() === 0 ) {
  //      ... autoCreateUser( $user, ... );
  //
  //
  // AuthManger::autoCreateUser()
  //  -> (providers)->testUserForCreation()
  //         -> default impl: return good
  //  -> (providers)->autoCreatedAccount()
  //       PluggableAuth:
  //         - updateUserRealNameAndEmail()
  //            -- realname/email are communicated via AuthenticationSessionData!
  //         - (plugin)::saveExtraAttributes()   [create link table entry]
  //  -> (Hook)onLocalUserCreated()
  //       PluggableAuth:
  //         - if autocreated:
  //           - (Hook)PluggableAuthPopulateGroups()
  //              - DSSOC::onPluggableAuthPopulateGroups()
  //                                       [update group assignments]


  /**
   * Get an/the instance of PluggableAuth's AuthenticationProvider.
   *
   * @return PrimaryAuthenticationProvider
   */
  private function getPluggableAuthInstance(
      AuthManager $authManager ) : PrimaryAuthenticationProvider {
    $instance =
        // For PluggableAuth ==5.7:
        $authManager->getAuthenticationProvider(
            PluggableAuthPrimaryAuthenticationProvider::class );
    // TODO(maddog) For PluggableAuth >=6.0:
    //  $authManager->getAuthenticationProvider(
    //      MediaWiki\Extension\PluggableAuth\PrimaryAuthenticationProvider::class );
    Util::insist( $instance instanceof PrimaryAuthenticationProvider );
    return $instance;
  }


  /**
   * Set up the session data that is expected by PluggableAuth and
   * our own AuthenticationPlugin, so that the existing machinery can be
   * used to create and/or update wiki user data.
   *
   * @param array $newWikiInfo new/revised wiki-info for the wiki user
   * @param array $newCredentials SSO credentials for the discourse user
   * @param AuthManager $authManager an instance of AuthManager
   *
   * @return void This function returns no value.
   */
  private function setupPluggableAuthSessionData(
      array $newWikiInfo,
      array $newCredentials,
      AuthManager $authManager) : void {
    // Stash data where PluggableAuth::autoCreatedAccount() will look for it.
    $authManager->setAuthenticationSessionData(
        PluggableAuthLogin::REALNAME_SESSION_KEY, $newWikiInfo['realname'] );
    $authManager->setAuthenticationSessionData(
        PluggableAuthLogin::EMAIL_SESSION_KEY, $newWikiInfo['email'] );
    // Stash data where our AuthenticationPlugin::saveExtraAttributes()
    // will look for it.
    $state = [ 'wiki_info' => $newWikiInfo,
               'sso_credentials' => $newCredentials, ];
    $authManager->setAuthenticationSessionData(
        AuthenticationPlugin::STATE_SESSION_KEY, $state );
  }


  /**
   * Create a new wiki user and link it to a discourse-id, by essentially
   * faking up a PluggableAuth authentication flow and thus invoking a bunch
   * of PluggableAuth machinery.
   *
   * @param array $newWikiInfo wiki-info for the new wiki user (with a null
   *         'id' value since no user exists yet)
   * @param array $newCredentials SSO credentials for the discourse user
   *
   * @return int the id of the newly created wiki user
   * @throws MWException on failure
   */
  private function createNewUser( array $newWikiInfo,
                                  array $newCredentials ) : int {
    Util::debug( __METHOD__ );
    // Let's emulate what the PluggableAuth extension does.
    //
    // We set up a bunch of session data (as would happen if our
    // AuthenticationPlugin were called during a login attempt),
    // and then call AuthManager::autoCreateUser().  That in turn
    // will call autoCreatedAccount() and onLocalUserCreated(),
    // and somewhere in there it will delegate to PluggableAuth
    // and our AuthenticationPlugin.

    // Construct a User instance (just an in-memory object).
    $user = User::newFromName( $newWikiInfo['username'], 'usable' );
    '@phan-var User|false $user'; // NB: newFromName() has mistyped @return.
    if ( !$user ) {
      throw new MWException(
          "Username '{$newWikiInfo['username']}' is not usable on this wiki." );
    }
    if ( $user->getId() !== 0 ) {
      throw new MWException(
          "Username '{$newWikiInfo['username']}' already exists with id '{$user->getId()}'." );
    }

    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    $this->setupPluggableAuthSessionData( $newWikiInfo, $newCredentials,
                                          $authManager );

    // Invoke AuthManager::autoCreateUser().
    $source = $this->getPluggableAuthInstance( $authManager )->getUniqueId();
    $status = $authManager->autoCreateUser( $user, $source, false /*$login*/ );
    if ( !$status->isGood() ) {
      throw new MWException(
          "autoCreateUser() for '{$user}' failed:  " .
          Status::wrap( $status )->getWikiText() );
    }

    // Yay, return the new user-id.
    Util::insist( $user->getId() !== 0 );
    return $user->getId();
  }


  /**
   * Update the wiki-info for an existing wiki user, by essentially
   * faking up a PluggableAuth authentication flow and thus invoking a bunch
   * of PluggableAuth machinery.  This will link the user to a discourse-id,
   * too.
   *
   * @param array $newWikiInfo wiki-info for the new wiki user
   * @param array $newCredentials SSO credentials for the discourse user
   *
   * @return void This function returns no value.
   */
  private function updateWikiUser( array $newWikiInfo,
                                   array $newCredentials ) : void {
    Util::debug( __METHOD__ );
    Util::debug(
        "updateWikiUser to: " . var_export( [ $newWikiInfo,
                                              $newCredentials ], true ) );
    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    $this->setupPluggableAuthSessionData( $newWikiInfo, $newCredentials,
                                          $authManager );

    $user = User::newFromId( $newWikiInfo['id'] );

    // Ensure $user is actually loaded from DB before trying to modify it;
    // PluggableAuth does not properly call User::setRealName() and
    // User::setEmail().
    $user->getRealName();
    $user->getEmail();
    // TODO(maddog)  Above should not be necessary for PluggableAuth >=6.0.

    $pluggableAuth = $this->getPluggableAuthInstance( $authManager );

    // This will (hopefully) update the realname/email of $user, if
    // PluggableAuth has been configured to allow that.  This will also
    // (hopefully) call back to our AuthenticationPlugin::saveExtraAttributes(),
    // which will create the discourse-id/wiki-id link entry.
    $pluggableAuth->autoCreatedAccount( $user, $pluggableAuth->getUniqueId() );

    // This will (definitely) update the wiki groups for $user according
    // to our configuration.
    Core::populateGroupsForUser( $user, $newCredentials );
  }


  // TODO(maddog) Someone needs to call this, no??
  //              A:  Save this functionality for >3.0.0.
  //                  (And, make it optional/configurable.)
  //              A:  Or, maybe it is just a bad idea to even bother.
  //
  // TODO(maddog) It would be better to check if the credentials introduce
  //              any *differences*... and only consider merging/updating the
  //              record in the DB if it makes a difference.
  //
  // TODO(maddog) Is email address actually provided by the webhook data?
  //                A: YES.
  //              If not, leave it out here!
  //              If so, need to respect the User.ExposeEmail config....
  //
  //              (If not) When writing a user-record to the DB, we should
  //              also ensure that no email field is present, ever, so that
  //              we do not accidentally start writing one if Discourse
  //              changes its mind.
  //
  //              Should we apply the same logic to the name field, which
  //              *is* provided by the webhook?
  //              ...HOWEVER:
  //              Discourse peppers realnames throughout the record, and it
  //              will be impossible to guarantee we found them all.
  //
  //              **SO:  we should just leave the webhook data alone,**
  //              **and not attempt to filter it,**
  //              and put warnings to the sysadmin in the README.
  //
  // TODO(maddog) That said, if ExposeEmail or ExposeName is false, we should
  //              be careful to *not ruin* the user-record data during a merge!
  //
  // mutates $user
  private static function updateUserRecordFromCredentials(
      object $user, array $credentials) : void {
    // Make sure we are talking about the same user!
    Util::insist( $user->id === $credentials['discourse_id'] );

    // Update the atomic elements - easy.
    $user->username = $credentials['username'];
    $user->name = $credentials['name'];
    $user->email = $credentials['email'];
    $user->admin = $credentials['is_admin'];
    $user->moderator = $credentials['is_moderator'];

    // Update the groups --- more complicated, because SSO credentials
    // only send group names (not unreasonable), but the user record
    // embeds entire group records.  Thus, we do our update to avoid
    // discarding any information:
    //  - If a group entry is in the credentials list, leave it alone.
    //  - If a group entry is not in the credentials list, remove it.
    //  - If a name on the credentials list does not already have a
    //    group entry, create a "stub" entry that just has the group name.
    //
    // Simulate a hash set using array keys.
    $credentialGroupNames = array_fill_keys( $credentials['groups'], true );
    // Keep groups listed in credentials.
    $remainingGroups = [];
    foreach ( $user->groups as $group ) {
      if ( array_key_exists( $group->name, $credentialGroupNames ) ) {
        $remainingGroups[] = $group;
        unset( $credentialGroupNames[$group->name] );
      }
    }
    // Synthesize group records for any un-accounted-for names.
    foreach ( array_keys($credentialGroupNames) as $name ) {
      // TODO(maddog)  Consider better typing of $credentials to enforce this.
      Util::insist( is_string( $name ) );
      $remainingGroups[] = self::synthesizeGroupRecord( $name );
    }
    // Update the user's groups.
    $user->groups = $remainingGroups;
  }


  private static function synthesizeGroupRecord( string $name ) : object {
    return (object) array( 'name' => $name );
  }


  /**
   * Retrieve and decode the JSON content POSTed in this request.
   * The decoded content will be cached.
   *
   * @return object bearing the decoded JSON.
   *
   * @throws \JsonException if decoding fails.
   */
  private function getPostedJson() : object {
    if ( $this->postedJson === null ) {
      $this->postedJson = Util::decodeJsonAsObject(
          $this->getRequest()->getRawPostString() );
    }
    return $this->postedJson;
  }

}
