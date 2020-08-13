<?php
/**
 * This file is part of Discourse_SSO_Consumer.
 *
 * Copyright 2020 Matt Marjanovic
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program (see the file `COPYING`); if not, write to the
 *
 *   Free Software Foundation, Inc.,
 *   59 Temple Place, Suite 330,
 *   Boston, MA 02111-1307
 *   USA
 *
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\DiscourseSsoConsumer;

use DatabaseUpdater;
use Exception;
use GlobalVarConfig;
use MediaWiki\Auth\AuthManager;
use MWException;
use PluggableAuth;
use RequestContext;
use SpecialPage;
use Title;
use User;


class DiscourseSsoConsumer extends PluggableAuth {

  /**
   * Prefix for our global configuration parameters
   */
  private const CONFIG_PREFIX = 'wgDiscourseSsoConsumer_';

  /**
   * Name of log-group we use for debug logging
   */
  private const LOG_GROUP = 'DiscourseSsoConsumer';

  /**
   * Key for state we save in AuthManager's authentication session data
   */
  private const STATE_SESSION_KEY = 'DiscourseSsoConsumerState';

  /**
   * Name of our extension table in the database
   */
  private const TABLE = 'discourse_sso_consumer';


  /**
   * @var GlobalVarConfig $config:  Access to this extension's
   *                                configuration parameters
   */
  private $config;


  public function __construct() {
    $this->config = new GlobalVarConfig( self::CONFIG_PREFIX );

    // Ensure that required parameters have been configured.
    if ( !$this->config->has( 'SsoProviderUrl' ) ) {
      throw new MWException( self::CONFIG_PREFIX .
                             'SsoProviderUrl is not configured.' );
    }
    if ( !$this->config->has( 'SharedSecret' ) ) {
      throw new MWException( self::CONFIG_PREFIX .
                             'SharedSecret is not configured.' );
    }
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
    $authManager = AuthManager::singleton();
    try {
      $state = $authManager->getAuthenticationSessionData(
        self::STATE_SESSION_KEY, null );

      // A little state machine:
      // TODO(maddog)  Need to either clear or ignore lingering 'credentials' state...
      if ( $state === null ) {
        $this->initiateAuthentication();
        // initiateAuthentication() should never return.
        self::unreachable();
      } elseif ( isset( $state['nonce'] ) ) {
        $localInfo = $this->completeAuthentication( $state['nonce'] );
        // completeAuthentication() should return a valid $localInfo.
        self::insist( is_array( $localInfo ) );
        // Set return values and return success.
        $id = $localInfo['id'];
        $username = $localInfo['username'];
        $realname = $localInfo['realname'];
        $email = $localInfo['email'];
        $errorMessage = null;
        return true;
      }
      // Else... something has gone wrong.
      wfDebugLog( self::LOG_GROUP,
                  'Unexpected state:  ' . var_export( $state, true ) );
      throw new MWException( 'Unknown protocol state error.' );

    } catch ( Exception $e ) {
      // TODO(maddog)  Would the entire exception/trace be better, like this:
      //          $errorMessage = $e->__toString();
      $errorMessage = $e->getMessage();
      wfLogWarning( self::LOG_GROUP . ' ' . $errorMessage );
      $authManager->setAuthenticationSessionData(
        self::STATE_SESSION_KEY, [ 'error' => $errorMessage ] );
      return false;
    }
  }


  /**
   * Implement PluggableAuth::saveExtraAttributes() interface.
   *
   * @param int $localId
   */
  public function saveExtraAttributes( $localId ) {
    $authManager = AuthManager::singleton();
    $state = $authManager->getAuthenticationSessionData(
      self::STATE_SESSION_KEY );

    $externalId = $state['sso_credentials']['external_id'];
    self::insist( $localId === $state['local_info']['id'] );
    self::insist( $externalId > 0 );

    self::updateIdLinkage( $externalId, $localId );
  }


  /**
   * Implement PluggableAuth::deauthenticate() interface.
   *
   * If LogoutDiscourse is enabled, a logout request will be sent to the
   * Discourse SSO Provider endpoint (and this function will *not* return).
   *
   * @param User &$user
   */
  public function deauthenticate( User &$user ) {
    $logoutDiscourse = $this->config->get( 'LogoutDiscourse' );
    if ( !$logoutDiscourse ) {
      return;
    }

    wfDebugLog( self::LOG_GROUP, 'Propagating logout back to Discourse...' );
    $returnAddress = null;
    $request = RequestContext::getMain()->getRequest();
    $returnTo = $request->getVal( 'returnto' );
    if ( $returnTo !== null ) {
      $title = Title::newFromText( $returnTo );
      if ( $title !== null ) {
        $returnAddress = $title->getFullURL();
      }
    }
    if ( $returnAddress === null ) {
      $returnAddress = Title::newMainPage()->getFullURL();
    }
    wfDebugLog( self::LOG_GROUP, "Return to: '{$returnAddress}'" );

    // Ensure session state is cleared out.  (Note that we don't care
    // about saving the nonce for this request, because the logout request
    // will not produce a response that we need to validate.)
    $authManager = AuthManager::singleton();
    $authManager->setAuthenticationSessionData( self::STATE_SESSION_KEY, null );

    $this->redirectToSsoEndpointAndExit( self::generateRandomNonce(),
                                         $returnAddress,
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
    $groupMaps = ( new GlobalVarConfig( self::CONFIG_PREFIX ) )->get( 'GroupMaps' );
    if ( !$groupMaps ) {
      wfDebugLog( self::LOG_GROUP, 'No GroupMaps configured.' );
      return;
    }

    wfDebugLog(
      self::LOG_GROUP,
      "Populating groups for user #{$user->getId()} '{$user->getName()}'..." );
    $state = AuthManager::singleton()->getAuthenticationSessionData(
      self::STATE_SESSION_KEY );
    self::insist( $state['local_info']['id'] === $user->getId() );
    $credentials = $state['sso_credentials'];

    // Get the user's external (Discourse) groups from the SSO credentials.
    $userExternalGroups = $credentials['groups'];
    // If a moderator or admin bits are set, add appropriate special tag groups.
    if ( $credentials['is_admin'] ) {
      $userExternalGroups[] = '@ADMIN@';
    }
    if ( $credentials['is_moderator'] ) {
      $userExternalGroups[] = '@MODERATOR@';
    }
    wfDebugLog( self::LOG_GROUP,
                'User\'s external groups: ' .
                var_export( $userExternalGroups, true ) );

    // Loop over the group map, collecting local groups to be added or removed.
    $toAdd = [];
    $toRemove = [];
    foreach ( $groupMaps as $mappingLocalGroup => $mappingExternalGroups ) {
      if ( array_intersect( $userExternalGroups,
                            $mappingExternalGroups ) === [] ) {
        $toRemove[] = $mappingLocalGroup;
      } else {
        $toAdd[] = $mappingLocalGroup;
      }
    }

    // Since addGroup() and removeGroup() are fairly heavyweight operations,
    // which call hooks and do database things even if membership is not going
    // to change,  we call them only if we need to modify the status of
    // membership in a group.
    $currentGroups = $user->getGroups();
    $toAdd = array_diff( $toAdd, $currentGroups );
    $toRemove = array_intersect( $toRemove, $currentGroups );
    foreach ( $toAdd as $group ) {
      wfDebugLog( self::LOG_GROUP, "Adding membership to '{$group}'." );
      $user->addGroup( $group );
    }
    foreach ( $toRemove as $group ) {
      wfDebugLog( self::LOG_GROUP, "Removing membership to '{$group}'." );
      $user->removeGroup( $group );
    }
  }


  /**
   * Implement LoadExtensionSchemaUpdates hook.
   *
   * @param DatabaseUpdater $updater
   */
  public static function onLoadExtensionSchemaUpdates(
    DatabaseUpdater $updater ) {
    $type = $updater->getDB()->getType();
    $updater->addExtensionTable(
      self::TABLE,
      __DIR__ . '/../sql/' . $type . '/add_table.sql' );
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
   * This method does not return.
   */
  private function initiateAuthentication() : void {
    wfDebugLog( self::LOG_GROUP, "Initiating authentication..." );

    $nonce = self::generateRandomNonce();

    // We will tell Discourse to send its response (via browser redirection)
    // to the magic "PluggableAuthLogin" page provided by PluggableAuth.
    $returnAddress = SpecialPage::getTitleFor(
      'PluggableAuthLogin' )->getFullURL();

    // Update session state (saving nonce for the next phase).
    $authManager = AuthManager::singleton();
    $authManager->setAuthenticationSessionData(
      self::STATE_SESSION_KEY, [ 'nonce' => $nonce ] );

    $this->redirectToSsoEndpointAndExit( $nonce,
                                         $returnAddress,
                                         false /*isLogout*/ );
  }


  /**
   * Construct a random nonce.
   *
   * @return string
   */
  private static function generateRandomNonce() : string {
    return hash( 'sha512', mt_rand() . time() );
  }


  /**
   * Construct a signed SSO request payload and redirect the user's browser
   * to the Discourse SSO provider endpoint with the request.
   *
   * @param string $nonce random nonce for this SSO interaction
   * @param string $returnAddress URL to which Discourse should return the
   *                              SSO results (via a browser redirect)
   * @param bool $isLogout true if Discourse should logout the user
   *
   * This method does not return.
   */
  private function redirectToSsoEndpointAndExit( string $nonce,
                                                 string $returnAddress,
                                                 bool $isLogout ) : void {
    wfDebugLog( self::LOG_GROUP, "Nonce: '{$nonce}'" );
    wfDebugLog( self::LOG_GROUP, "Return address URL: '{$returnAddress}'" );
    wfDebugLog( self::LOG_GROUP, "Is logout? '{$isLogout}'" );
    $payload = base64_encode(
      http_build_query( [ 'nonce' => $nonce,
                          'return_sso_url' => $returnAddress,
                          'logout' => ( $isLogout === true ? "true" : "false" ),
      ] ) );

    // Sign the payload with the shared-secret, and construct the parameters
    // for the SSO request URL.
    $sharedSecret = $this->config->get( 'SharedSecret' );
    $ssoParameters = [ 'sso' => $payload,
                       'sig' => hash_hmac( 'sha256',
                                           $payload, $sharedSecret ), ];

    // Redirect user's browser to Discourse.
    $ssoProviderUrl = $this->config->get( 'SsoProviderUrl' );
    wfDebugLog( self::LOG_GROUP,
                "Redirecting to Discourse at '{$ssoProviderUrl}'." );
    self::redirectToUrlAndExit( $ssoProviderUrl, $ssoParameters );
  }


  /**
   * Complete the SSO handshake, processing the response from the
   * Discourse SSO endpoint.  If authentication succeeds, this returns
   * an array bearing the local id, username, realname, and email address
   * of the authenticated user.  The local id may be null if the user does
   * not yet exist in MediaWiki.
   *
   * @param string $originalNonce the nonce used to initiate the handshake
   *
   * @return array if authentication is successful
   * @throws MWException if authentication fails
   */
  private function completeAuthentication( string $originalNonce ) : array {
    wfDebugLog( self::LOG_GROUP, "Completing authentication..." );

    $ssoCredentials = $this->validateAndUnpackCredentials( $originalNonce );
    $externalId = $ssoCredentials['external_id'];

    wfDebugLog( self::LOG_GROUP,
                'Received credentials: ' . var_export( $ssoCredentials, true ) );

    // Lookup external_id in our registry.
    $dbLookup = $this->lookupExternalId( $externalId );
    wfDebugLog(
      self::LOG_GROUP,
      'Lookup external_id in database:  ' . var_export( $dbLookup, true ) );

    $localInfo = null;
    if ( !$dbLookup ) {
      // The external_id is not known to us, so we need to either link
      // to an existing MW user, or create a brand-new one.
      wfDebugLog( self::LOG_GROUP,
                  "external_id '{$externalId}' is new to us." );
      $localInfo = $this->handleUnknownUser( $ssoCredentials );
    } else {
      // The external_id is known to us, so we merely need to update
      // our registry and return the current user info.
      wfDebugLog( self::LOG_GROUP,
                  "external_id '{$externalId}' is mapped to " .
                  "local id '{$dbLookup->local_id}'." );
      // We (must) retain the existing local id and username, but we provide
      // the possibly-updated real name and email from Discourse credentials.
      $localInfo = [
        'id' => $dbLookup->local_id,
        'username' => $dbLookup->local_username,
        'realname' => $this->wikifyName( $ssoCredentials['name'] ),
        'email' => $this->wikifyEmail( $ssoCredentials['email'] ),
      ];
      // TODO(maddog) PluggableAuth will take care of updating the user's
      //              realname and email address (if we provide the new
      //              versions).  Changing a *username*, however, is a much
      //              more involved process (i.e., see Extension:Renameuser),
      //              so we don't try to do that yet.  In the meantime, if
      //              the Discourse username has diverged from the MediaWiki
      //              username, we should produce some kind of warning about
      //              the mismatch.  This may require tracking the Discourse
      //              username in our little DB table.
    }

    // At this point, we should have SSO credentials describing the user,
    // as well as complementary local information.
    self::insist( is_array( $ssoCredentials ) );
    self::insist( is_array( $localInfo ) );

    // Update state.
    $state = [ 'sso_credentials' => $ssoCredentials,
               'local_info' => $localInfo ];
    $authManager = AuthManager::singleton();
    $authManager->setAuthenticationSessionData(
      self::STATE_SESSION_KEY, $state );

    return $localInfo;
  }


  /**
   * Handle SSO credentials bearing a Discourse user id which has not yet
   * been associated to a local id.  Depending on configuration, this may
   * involve attempting to match to an existing local user by username or
   * email.
   *
   * If no matching local id is found, a new username (based on the Discourse
   * username) will be constructed and returned (with a null local id) to
   * cause a new local account to be created.
   *
   * @param array $ssoCredentials the SSO credentials
   *
   * @return array bearing local account info for the authenticated SSO user
   * @throws MWException if local account info cannot be determined/created
   */
  private function handleUnknownUser( array $ssoCredentials ) : array {
    $externalId = $ssoCredentials['external_id'];
    $localInfo = [
      'id' => null,
      'username' => self::wikifyUsername( $ssoCredentials['username'] ),
      'realname' => $this->wikifyName( $ssoCredentials['name'] ),
      'email' => $this->wikifyEmail( $ssoCredentials['email'] ),
    ];

    $found = null;
    foreach ( $this->config->get( 'LinkExistingBy' ) as $method ) {
      wfDebugLog( self::LOG_GROUP, "Trying LinkExistingBy '{$method}'..." );
      if ( $method === 'username' ) {
        // Attempt to find a user with "same" username as in credentials.
        $found = self::findUserByUsername( $localInfo['username'] );
      } elseif ( $method === 'email' ) {
        // Attempt to find a user with same email address as in credentials.
        $found = $this->findUserByEmail( $localInfo['email'] );
        if ( ( !$found ) && ( $this->config->get( 'ExposeEmail' ) !== true ) ) {
          wfDebugLog( self::LOG_GROUP,
                      'LinkExistingBy:Email requires ExposeEmail:true.' );
        }
      } else {
        wfDebugLog( self::LOG_GROUP,
                    "Ignoring unknown 'LinkExistingBy' method: '{$method}'." );
      }
      if ( $found ) {
        wfDebugLog(
          self::LOG_GROUP,
          "Linking external user #{$externalId} '{$ssoCredentials['username']}'"
          . " to local user #{$found->local_id} '{$found->local_username}'." );
        $localInfo['id'] = $found->local_id;
        $localInfo['username'] = $found->local_username;

        self::updateIdLinkage( $externalId, $found->local_id );

        // $method has succeeded, so don't try any more methods.
        break;
      }
    }

    if ( !$localInfo['id'] ) {
      // Still no link?  Then we need to create a new MW user.  We only
      // supply the user info; AuthManager machinery will decide whether or
      // not user creation is allowed and take care of it.  If/when a new
      // user is auto-created, saveExtraAttributes() will be automagically
      // called, and that is when we will insert the new link record in our
      // table.
      //
      // All we need to do is (try to) make sure we supply an available new
      // username.
      $localInfo['username'] =
                             self::ensureFreshUsername( $localInfo['username'] );
      wfDebugLog(
        self::LOG_GROUP,
        "New local user '{$localInfo['username']}' needs to be created." );
    }

    return $localInfo;
  }


  /**
   * Validate the response from the Discourse SSO Provider endpoint and
   * unpack the SSO credentials.
   *
   * @param string $originalNonce the nonce used to initiate this
   *                              authentication flow
   *
   * @return array bearing the SSO credentials
   * @throws MWException if validation or unpacking fails
   */
  private function validateAndUnpackCredentials( string $originalNonce )
    : array {
    $request = RequestContext::getMain()->getRequest();
    $sso = $request->getVal( 'sso' );
    $sig = $request->getVal( 'sig' );
    if ( !$sso || !$sig ) {
      throw new MWException(
        'Missing sso or sig parameters in Discourse SSO response.' );
    }

    $sharedSecret = $this->config->get( 'SharedSecret' );
    if ( hash_hmac( 'sha256', $sso, $sharedSecret ) !== $sig ) {
      throw new MWException( 'sig does not match hashed sso payload.' );
    }
    // At this point, we know that the current request was generated by the
    // Discourse server (with our shared-secret).

    $unpackedResponse = self::unpackSsoResponse( $sso );

    if ( $unpackedResponse['nonce'] !== $originalNonce ) {
      // Replay attack?  Crossed wires?  Fail!
      throw new MWException( 'Response nonce does not match request.' );
    }
    // At this point, we know that the current request was indeed a response
    // to this authentication flow (rather than a replay attack).

    // We should not receive a non-positive Discourse ID; if we do, something
    // is wrong (in Discourse, or in our own parsing).
    if ( $unpackedResponse['credentials']['external_id'] <= 0 ) {
      throw new MWException(
        "Invalid external_id ({$unpackedResponse['credentials']['external_id']}) received." );
    }

    return $unpackedResponse['credentials'];
  }


  /**
   * Unpack and parse an SSO payload.
   *
   * @param string $packedResponse the packed/encoded "sso" parameter from
   *                               the Discourse SSO provider's response.
   *
   * @return array the parsed elements of the SSO payload
   */
  private static function unpackSsoResponse( string $packedResponse ) : array {
    $decoded = base64_decode( $packedResponse );
    wfDebugLog( self::LOG_GROUP,
                "Received response:  '{$decoded}'" );

    $parsed = [];
    parse_str( $decoded, $parsed );

    $response = [
      'nonce' => $parsed['nonce'],
      'return_sso_url' => $parsed['return_sso_url'],
      'credentials' => [
        'external_id' => (int)$parsed['external_id'],
        'username' => $parsed['username'],
        'name' => $parsed['name'],
        'email' => $parsed['email'],
        'groups' => explode( ',', $parsed['groups'] ),
        'is_admin' => ( $parsed['admin'] === 'true' ) ? true : false,
        'is_moderator' => ( $parsed['moderator'] === 'true' ) ? true : false,
      ],
    ];
    return $response;
  }


  /**
   * Send a redirect response to the browser and exit request handling.
   *
   * @param string $url the URL to redirect to
   * @param array $params parameters (as key:value pairs) to attach to the URL
   *
   * This method does not return.
   */
  private static function redirectToUrlAndExit( string $url, array $params )
    : void {
    $complete_url = $url . '?' . http_build_query( $params );

    header( 'Location: ' . $complete_url );
    exit( 0 );
  }


  /**
   * Given an external id (Discourse user id), find the associated local id
   * and local username.
   *
   * @param int $externalId the target external id
   *
   * @return object bearing members external_id, local_id, and local_username
   *                if $externalId is known, else null
   */
  private static function lookupExternalId( int $externalId ) : ?object {
    $dbr = wfGetDB( DB_REPLICA );
    $row = $dbr->selectRow(
      // tables
      [ 'dsc' => self::TABLE, 'u' => 'user' ],
      // columns/variables
      [ 'external_id' => 'dsc.external_id',
        'local_id' => 'u.user_id',
        'local_username' => 'u.user_name' ],
      // conditions (WHERE)
      [ 'dsc.external_id' => $externalId, ],
      // fname
      __METHOD__,
      // options
      [],
      // join conditions
      [ 'u' => [ 'JOIN', [ 'u.user_id=dsc.local_id' ] ] ]
    );
    if ( !$row ) {
      return null;
    }
    # DB results appear to be strings even if a column is typed as integer.
    $row->external_id = (int)$row->external_id;
    $row->local_id = (int)$row->local_id;
    self::insist( $row->external_id > 0 );
    self::insist( $row->local_id > 0 );
    self::insist( strlen( $row->local_username ) > 0 );
    return $row;
  }


  /**
   * Find a local (Mediawiki) user with the given email address.
   *
   * @param string $email the target email address
   *
   * @return object bearing members local_id and local_username
   *                if $email is known, else null
   */
  private static function findUserByEmail( string $email ) : ?object {
    // find user record with matching email address
    if ( $email === '' ) {
      wfDebugLog( self::LOG_GROUP, 'Email address is empty string, skipping.' );
      return null;
    }
    // TODO(maddog) Nothing here or in our schema prevents the possibility of
    //              two Discourse users becoming linked to the same MW user.
    //              Is this a problem?
    // TODO(maddog) What happens (and what should happen) if the email address
    //              is used by multiple local users?
    $dbr = wfGetDB( DB_REPLICA );
    $row = $dbr->selectRow(
      // tables
      [ 'u' => 'user' ],
      // columns/variables
      [ 'local_id' => 'u.user_id',
        'local_username' => 'u.user_name' ],
      // conditions (WHERE)
      [ 'u.user_email' => $email, ],
      // fname
      __METHOD__,
      // options
      [],
      // join conditions
      []
    );
    if ( !$row ) {
      return null;
    }
    # DB results appear to be strings even if a column is typed as integer.
    $row->local_id = (int)$row->local_id;
    self::insist( $row->local_id > 0 );
    self::insist( strlen( $row->local_username ) > 0 );
    return $row;
  }


  /**
   * Find a local (Mediawiki) user with the given username.
   *
   * @param string $username the target username
   *
   * @return object bearing members local_id and local_username (=== $username)
   *                if $username is known, else null
   */
  private static function findUserByUsername( string $username ) : ?object {
    // find user record with matching canonicalized username
    if ( $username === '' ) {
      wfDebugLog( self::LOG_GROUP, 'Username is empty string, skipping.' );
      return null;
    }
    // TODO(maddog) Nothing here or in our schema prevents the possibility of
    //              two Discourse users becoming linked to the same MW user.
    //              Is this a problem?
    $dbr = wfGetDB( DB_REPLICA );
    $row = $dbr->selectRow(
      // tables
      [ 'u' => 'user' ],
      // columns/variables
      [ 'local_id' => 'u.user_id',
        'local_username' => 'u.user_name' ],
      // conditions (WHERE)
      [ 'u.user_name' => $username, ],
      // fname
      __METHOD__,
      // options
      [],
      // join conditions
      []
    );
    if ( !$row ) {
      return null;
    }
    # DB results appear to be strings even if a column is typed as integer.
    $row->local_id = (int)$row->local_id;
    self::insist( $row->local_id > 0 );
    self::insist( $row->local_username === $username );
    return $row;
  }


  /**
   * Find a username derived from (and possibly identical to) $originalUsername
   * and not currently used by any user in this MediaWiki site.
   *
   * @param string $originalUsername source username
   *
   * @return string the resulting fresh username
   * @throws MWException if fails to find a fresh username
   */
  private static function ensureFreshUsername( string $originalUsername )
    : string {
    $suffix = 1;
    $username = $originalUsername;
    while ( User::idFromName( $username ) !== null ) {
      if ( $suffix > 1000 ) {
        throw MWException(
          "Failed to find fresh username for '{$originalUsername}'" .
          " after {$suffix} tries." );
      }
      $username = $originalUsername . '-' . $suffix;
      $suffix += 1;
    }
    return $username;
  }


  // TODO(maddog)  Document possible throws?
  /**
   * Link a (Discourse) external id to a (MediaWiki) local id in the database.
   *
   * @param int $externalId the external id
   * @param int $localId the local id
   *
   * @return void  This method does not return a value.
   */
  private static function updateIdLinkage( int $externalId, int $localId )
    : void {
    wfDebugLog(
      self::LOG_GROUP,
      "Linking external-id '{$externalId}' to local-id '{$localId}'." );
    $dbw = wfGetDB( DB_MASTER );
    $dbw->upsert(
      // table
      self::TABLE,
      // rows
      [ 'external_id' => $externalId,
        'local_id' => $localId, ],
      // uniqueIndexes
      [ 'external_id' ],
      // set
      [ 'local_id' => $localId, ],
      __METHOD__
    );
    // TODO(maddog)  Add/update a timestamp column.
  }
  // TODO(maddog) If the Discourse URL is changed (e.g., connect somewhere
  //              else), shouldn't the entire linking-table be invalidated
  //              somehow?  Should the Discourse URL be stored with each link
  //              record? Should there be a way to purge known-bogus/stale
  //              records?


  /**
   * Transform a username from SSO credentials into the appropriate form for
   * the MediaWiki site.
   *
   * @param string $ssoUsername username provided by SSO credentials
   *
   * @return string the resulting username
   * @throws MWException if $ssoUsername cannot be wikified
   */
  private static function wikifyUsername( string $ssoUsername ) : string {
    // Discourse usernames are case-preserving, but otherwise
    // case-insensitive. Mixed-case usernames are allowed, but case is
    // ignored when comparing/matching usernames.
    //
    // Conversely, usernames in MediaWiki are case-sensitive, *and* they
    // have the peculiar feature that the first letter is always upper-cased
    // (for those alphabets where that has any meaning).
    //
    // So, to "wikify" a Discourse username, we (likely) have to muck with
    // the case of the first letter.  And when linking a Discourse user to
    // an existing MediaWiki user for the first time, it is possible that
    // there are multiple existing MW users that have matching usernames in
    // the eyes of Discourse.

    // Ask for a valid page title made from $ssoUsername (since users are
    // just pages, like every atom in the MW universe).  This will
    // canonicalize the name according to how the MW site has been
    // configured.
    $title = Title::makeTitleSafe( NS_USER, $ssoUsername );
    if ( !$title ) {
      throw new MWException(
        "Unable to make a valid username from '{$ssoUsername}'." );
    }
    return $title->getText();
  }


  /**
   * Transform a (full, real) name from SSO credentials into the appropriate
   * form for the MediaWiki site.
   *
   * @param string $ssoName name provided by SSO credentials
   *
   * @return string the resulting name
   */
  private function wikifyName( string $ssoName ) : string {
    // Both MediaWiki and Discourse treat the full-/real-name as an opaque
    // string, so no tranformation is necessary except for hiding.
    if ( $this->config->get( 'ExposeName' ) === true ) {
      return $ssoName;
    }
    return '';
  }


  /**
   * Transform an email address from SSO credentials into the appropriate
   * form for the MediaWiki site.
   *
   * @param string $ssoEmail email address provided by SSO credentials
   *
   * @return string the resulting address
   */
  private function wikifyEmail( string $ssoEmail ) : string {
    // Both MediaWiki and Discourse treat the email address as an opaque
    // string, so no tranformation is necessary except for hiding.
    if ( $this->config->get( 'ExposeEmail' ) === true ) {
      return $ssoEmail;
    }
    return '';
  }


  /**
   * Strictly assert a runtime invariant, logging an error and backtrace
   * if $condition is false.
   *
   * @param bool $condition the asserted condition
   *
   * @return void This function returns no value; if $condition is false,
   *              it does not return, period.
   */
  private static function insist( bool $condition ) : void {
    if ( !$condition ) {
      // The $callerOffset parameter "2" tells wfLogWarning to identify
      // the function/line that called us as the location of the error.
      wfLogWarning( self::LOG_GROUP . ' INSIST FAILED', 2 );
      exit( 1 );
    }
  }


  /**
   * Log an error and backtrace indicating that purportedly unreachable code
   * has in fact been reached.
   *
   * This function does not return.
   */
  private static function unreachable() : void {
    // The $callerOffset parameter "2" tells wfLogWarning to identify
    // the function/line that called us as the location of the error.
    wfLogWarning( self::LOG_GROUP . ' REACHED THE UNREACHABLE', 2 );
    exit( 1 );
  }

}
