<?php
/**
 * This file is part of DiscourseSsoConsumer.
 *
 * Copyright 2020,2021 Matt Marjanovic
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

use DatabaseUpdater;
use Exception;
use ExtensionRegistry;
use GlobalVarConfig;
use IDatabase;
use MediaWiki\MediaWikiServices;
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
   * Version of our schema required by this code
   */
  private const SCHEMA_VERSION = 3;

  /**
   * Name of our metadata table in the database
   */
  private const META_TABLE = 'discourse_sso_consumer_meta';

  /**
   * Name of our id-linkage table in the database
   */
  private const LINK_TABLE = 'discourse_sso_consumer_link';

  /**
   * Name of our cookie, for noting that a user has logged in with us
   */
  private const COOKIE = 'DiscourseSsoConsumer';


  /**
   * @var GlobalVarConfig $config:  Access to this extension's
   *                                configuration parameters
   */
  private $config;


  public function __construct() {
    $this->config = new GlobalVarConfig( self::CONFIG_PREFIX );

    // Ensure that the database's schema will work with this code.
    $currentDbSchema = self::fetchSchemaVersion( wfGetDB( DB_REPLICA ) );
    if ( $currentDbSchema === null ) {
      throw new MWException(
          "DB does not have our schema at all.  " .
          "Did you forget to run 'maintenance/update.php'?" );
    } elseif ( $currentDbSchema !== self::SCHEMA_VERSION ) {
      throw new MWException(
          "DB has schema {$currentDbSchema}, but this code requires schema " .
          self::SCHEMA_VERSION .
          ".  Did you forget to run 'maintenance/update.php'?" );
    }
    // Ensure that required parameters (those without default values) have
    // been configured.  (Fail early on broken configuration.)
    if ( $this->config->get( 'DiscourseUrl' ) === null ) {
      throw new MWException( '$' . self::CONFIG_PREFIX .
                             'DiscourseUrl is not configured.' );
    }
    if ( $this->config->get( 'SsoSharedSecret' ) === null ) {
      throw new MWException( '$' . self::CONFIG_PREFIX .
                             'SsoSharedSecret is not configured.' );
    }
    if ( $this->config->get( 'EnableDiscourseLogout' ) &&
         ( $this->config->get( 'LogoutApiKey' ) === null ) ) {
      throw new MWException( '$' . self::CONFIG_PREFIX .
                             'LogoutApiKey is not configured.' );
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
    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    try {
      $state = $authManager->getAuthenticationSessionData(
        self::STATE_SESSION_KEY, null );

      // A little state machine:
      // TODO(maddog)  Need to either clear or ignore lingering 'credentials' state...
      if ( $state === null ) {
        // Clear our success marker first.  If authentication fails (whether
        // due to an error or due to user declining to complete authentication),
        // we do not want the user stuck returning to Discourse to keep trying.
        self::clearDssoCookie();
        // Now, start talking to Discourse.
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
        // Since authentication a success, remember that we did it.
        self::setDssoCookie();
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
    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    $state = $authManager->getAuthenticationSessionData(
      self::STATE_SESSION_KEY );

    $externalId = $state['sso_credentials']['external_id'];
    self::insist( $externalId > 0 );

    if ( !$state['local_info']['id'] ) {
      // If the local id in $state is zero, that means that we did not have
      // an id earlier in the process because a new user needed to be created.
      // The id of the new user entry has now been provided to us in $localId,
      // so we update our session state.
      $state['local_info']['id'] = $localId;
      $authManager->setAuthenticationSessionData(
          self::STATE_SESSION_KEY, $state );
    }
    self::insist( $localId === $state['local_info']['id'] );

    self::updateIdLinkage( $externalId, $localId );
  }


  /**
   * Implement PluggableAuth::deauthenticate() interface.
   *
   * If DiscourseLogout is enabled, a synchronous logout request will be
   * sent to the Discourse log_out API endpoint.
   *
   * @param User &$user
   */
  public function deauthenticate( User &$user ) {
    wfDebugLog( self::LOG_GROUP, 'deauthenticate()...' );

    // The user is explicitly logging out; clear our success cookie so that
    // we will not automatically try to reauthenticate to Discourse.
    self::clearDssoCookie();

    if ( $this->config->get( 'EnableDiscourseLogout' ) ) {
      $localId = $user->getId();
      $externalId = $this->lookupExternalIdByLocalId( $localId );
      if ( $externalId !== null ) {
        $this->logoutFromDiscourse( $externalId );
      } else {
        // It is possible (especially if $wgPluggableAuth_EnableLocalLogin is
        // true) that $user was not ever authenticated by Discourse, and thus
        // has no linked external-id.  So, we just log this case and move on.
        wfDebugLog(
          self::LOG_GROUP,
          "User '{$user->getName()}' (local-id {$localId}) not linked to " .
          "any external-id; skipping Discourse logout." );
      }
    }
  }


  /**
   * Implement BeforeInitialize hook.
   *
   * Following the example of PluggableAuth's use of this hook for its
   * own 'EnableAutoLogin' feature, we use this hook to implement our
   * 'AutoRelogin' feature.  Very early in page processing, we check
   * if the user needs to reauthenticate via DiscourseSSO (e.g., because
   * their MW session expired).
   *
   * @param Title $title
   * @param Null $unused
   * @param OutputPage $out
   * @param User $user
   * @param WebRequest $request
   * @param MediaWiki $mw
   *
   * @return void  This method does not return a value, and may not return
   *               at all in case it redirects to the login flow.
   */
  public static function onBeforeInitialize(
      $title, $unused, $out, $user, $request, $mw ) {
    // If "Auto Re-Login" is not enabled, nothing to do here.
    $config = new GlobalVarConfig( self::CONFIG_PREFIX );
    if ( !$config->get( 'EnableAutoRelogin' ) ) {
      return;
    }

    // Bail if the user is still logged in.
    if ( !$out->getUser()->isAnon() ) {
      wfDebugLog( self::LOG_GROUP,
                  "Skip auto re-login: user still logged in." );
      return;
    }
    // Bail if there is no incoming DSSO cookie --- which implies that
    // the user had explicitly logged out, or that no user had ever logged in
    // via DSSO.
    if ( $request->getCookie( self::COOKIE ) === null ) {
      wfDebugLog( self::LOG_GROUP,
                  "Skip auto re-login: user hadn't been logged in." );
      return;
    }
    // Bail if this page request is already in the login flow.
    $loginPages = ExtensionRegistry::getInstance()->getAttribute(
        'PluggableAuthLoginSpecialPages' );
    foreach ( $loginPages as $page ) {
      if ( $title->isSpecial( $page ) ) {
        wfDebugLog( self::LOG_GROUP,
                    "Skip auto re-login:  already busy logging in." );
        return;
      }
    }

    // Still here?  Ok, redirect to the login/authentication flow.
    wfDebugLog( self::LOG_GROUP, "Auto re-login:  going to reauthenticate..." );
    $originalTitle = $title;
    $redirectUrl = SpecialPage::getTitleFor( 'Userlogin' )->getFullURL( [
        'returnto' => $originalTitle,
        'returntoquery' => $request->getRawQueryString()
                                                                         ] );
    header( 'Location: ' . $redirectUrl );
    exit( 0 );
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
    $authManager = MediaWikiServices::getInstance()->getAuthManager();
    $state = $authManager->getAuthenticationSessionData(
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
   *
   * @return bool returns true (if it returns)
   * @throws MWException if status or an update plan cannot be determined.
   */
  public static function onLoadExtensionSchemaUpdates(
      DatabaseUpdater $updater ): bool {
    // Schema versions correspond to patch files, and every patch file
    // performs an idempotent operation in the evolution of the schema.
    // "Idempotent" in the sense that:
    //
    //  o If patch(N) fails at any point, the DB will be left unchanged.
    //  o If patch(N) successfully runs to completion, the DB will have been
    //    transformed to state (N).
    //  o A patch(N) will fail if executed on a DB that is not in state (N-1).
    //
    // In other words, it is always "safe" to execute a patch file; it will
    // either transform the DB's schema from (N-1) to (N), or it will fail
    // without modifying the DB.
    //
    // So far, our patch files work equally well with all three flavors of
    // DBMS (postgresql, sqlite, mysql).  We may need to specialize in the
    // future (e.g., if we add a column to a table, since sqlite does not
    // support ALTER TABLE).
    $dbSchemaVersion = self::fetchSchemaVersion( $updater->getDB() );

    if ( $dbSchemaVersion === null ) {
      // Blank slate:  install latest schema from scratch.
      self::insist( self::SCHEMA_VERSION === 3 );
      self::installSchemaVnnnn( 'v0003', $updater );

    } elseif ( $dbSchemaVersion === self::SCHEMA_VERSION ) {
      // Nothing to do; we are already up-to-date.

    } elseif ( $dbSchemaVersion > self::SCHEMA_VERSION ) {
      // Hmmm... database's schema is from the future?
      // More likely, we are from the past.
      throw new MWException(
          'Crack in the track! ' .
          'We cannot go forward, and we must not go back!... ' .
          'Code wants to update to schema version ' . self::SCHEMA_VERSION .
          ', but DB already has version ' . $dbSchemaVersion . '.' );

    } else { // ( $dbSchemaVersion < self::SCHEMA_VERSION )
      // Database needs to be updated; assemble a patch chain.
      switch ( $dbSchemaVersion ) {
        case 0:
          self::applySchemaPatchVnnnn( 'v0001', $updater );
        case 1:
          self::applySchemaPatchVnnnn( 'v0002', $updater );
        case 2:
          self::applySchemaPatchVnnnn( 'v0003', $updater );
          break;
        default:
          self::unreachable();
      }
    }

    return true;

    // TODO(maddog)  If we want to have "rolling schema updates" in the sense
    //               that we accommodate:
    //                 1. upgrade schema to enable a new feature, but stay
    //                    compatible with earlier code;
    //                 2. upgrade code, with opportunity to revert to prior
    //                    version;
    //                 3. upgrade schema to remove compatibility with earlier
    //                    code.
    //               we could split the "required self::SCHEMA_VERSION"
    //               concept into "self::MINIMUM_SCHEMA_VERSION" in the code
    //               and "'minimumCodeVersion'" in the DB metadata.  Then:
    //                 1. bumps DB schemaVersion, leaves minCodeVersion alone;
    //                 2. bumps code's MIN_SCHEMA_VERSION;
    //                 3. bumps DB's schemaVersion and minimumCodeVersion.
    //
    // TODO(maddog)  Schema changes could turn out to be 2-dimensional, if
    //               there is a dependency between the extension schema and
    //               the MW core schema (e.g., a foreign key constraint on
    //               a core table that gets a name change):
    //
    //                    1.35  1.36  1.37  1.38
    //                v0    *     *
    //                v1    *     *
    //                v2    *     *     *
    //                v3    *     *     *
    //                v4    *     *     *     *
    //
    //               So updates will need to choose a path from whatever the
    //               current/starting point is, to the new/current endpoint.
    //
    //               If this is needed, META will need to record the MW version
    //               as well.
    //
    //               Complication:  when does the core update happen?  What
    //               if the extension needs to unwind something before the
    //               core update can proceed?
  }


  /**
   * Fetch the current version of this extension's schema from the database.
   *
   * @param IDatabase $dbr the database to query
   *
   * @return ?int the version number of the schema, or null if no schema has
   *  been installed
   *
   * @throws MWException if a version should exist but cannot be determined
   */
  private static function fetchSchemaVersion( IDatabase $dbr ): ?int {
    if ( !$dbr->tableExists( self::META_TABLE ) ) {
      // No metatable could mean no schema, or "Original Recipe(tm)" schema.
      if ( !$dbr->tableExists( 'discourse_sso_consumer' ) ) {
        return null;
      }
      return 0;
    }

    $row = $dbr->selectRow(
      [ 'meta' => self::META_TABLE ], // tables
      [ 'key' => 'meta.m_key',
        'value' => 'meta.m_value', ], // columns/variables
      [ 'meta.m_key' => 'schemaVersion', ], // conditions (WHERE)
      __METHOD__, // fname
      [], // options
      [] // join conditions
    );
    if ( !$row ) {
      throw new MWException(
          "Metadata table is broken:  no entry for 'schemaVersion'!" );
    }
    self::insist( $row->key === 'schemaVersion' );
    self::insist( is_numeric( $row->value ) );
    return intval( $row->value );
  }


  /**
   * Instruct DatabaseUpdater to install a schema from scratch.
   *
   * @param string $vnnnn version of schema to install, e.g., "v0017"
   * @param DatabaseUpdater $updater the updater doing the work
   * @return void
   */
  private static function installSchemaVnnnn( string $vnnnn,
                                              DatabaseUpdater $updater ): void {
    $patchFilePath = __DIR__ . "/../sql/schema-{$vnnnn}.sql";
    $updater->addExtensionUpdate(
        [ 'applyPatch', $patchFilePath, true,
          "Installing DiscourseSsoConsumer schema {$vnnnn} via '{$patchFilePath}'" ] );
  }


  /**
   * Instruct DatabaseUpdater to apply a schema patch.
   *
   * @param string $vnnnn version of patch to apply, e.g., "v0017"
   * @param DatabaseUpdater $updater the updater doing the work
   * @return void
   */
  private static function applySchemaPatchVnnnn(
      string $vnnnn, DatabaseUpdater $updater ): void {
    $patchFilePath = __DIR__ . "/../sql/patch-{$vnnnn}.sql";
    $updater->addExtensionUpdate(
        [ 'applyPatch', $patchFilePath, true,
          "Patching DiscourseSsoConsumer schema to {$vnnnn} via '{$patchFilePath}'" ] );
  }


  /**
   * Set our own cookie, to indicate successful authentication via DSSO.
   *
   * The value of the cookie is irrelevant; only its presence matters.
   * MW's defaults for cookie parameters should be sufficient.
   *
   * @return void This function returns no value.
   */
  private static function setDssoCookie(): void {
    $response = RequestContext::getMain()->getRequest()->response();
    $response->setCookie( self::COOKIE, "BarneyCollier" );
  }


  /**
   * Clear our own cookie.
   *
   * @return void This function returns no value.
   */
  private static function clearDssoCookie(): void {
    $response = RequestContext::getMain()->getRequest()->response();
    $response->clearCookie( self::COOKIE );
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
    $authManager = MediaWikiServices::getInstance()->getAuthManager();
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
    $sharedSecret = $this->config->get( 'SsoSharedSecret' );
    $ssoParameters = [ 'sso' => $payload,
                       'sig' => hash_hmac( 'sha256',
                                           $payload, $sharedSecret ), ];

    // Redirect user's browser to Discourse.
    $ssoProviderUrl =
        $this->config->get( 'DiscourseUrl' ) .
        $this->config->get( 'SsoProviderEndpoint' );
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
    $authManager = MediaWikiServices::getInstance()->getAuthManager();
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

        // TODO(maddog) Our schema prevents the possibility of two Discourse
        //              users becoming linked to the same MW user, via a
        //              uniqueness constraint, but should we try to discover
        //              this case before calling updateIdLinkage()?
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

    $sharedSecret = $this->config->get( 'SsoSharedSecret' );
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

    // NB: 'name' can be missing from the response if it was never set
    // in Discourse; e.g., this can happen for Discourse users created
    // by import from another forum.
    $response = [
      'nonce' => $parsed['nonce'],
      'return_sso_url' => $parsed['return_sso_url'],
      'credentials' => [
        'external_id' => (int)$parsed['external_id'],
        'username' => $parsed['username'],
        'name' => $parsed['name'] ?? '',
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
      [ 'dsc' => self::LINK_TABLE, 'u' => 'user' ],
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
   * Given a local id (MediaWiki user id), find the associated external id
   * (Discourse user id).
   *
   * @param int $localId the target local id
   *
   * @return ?int the associated external id if $localId is known, else null
   */
  private static function lookupExternalIdByLocalId( int $localId ) : ?int {
    $dbr = wfGetDB( DB_REPLICA );
    $row = $dbr->selectRow(
      // tables
      [ 'dsc' => self::LINK_TABLE ],
      // columns/variables
      [ 'external_id' => 'dsc.external_id',
        'local_id' => 'dsc.local_id' ],
      // conditions (WHERE)
      [ 'dsc.local_id' => $localId, ],
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
    $external_id = (int)$row->external_id;
    self::insist( $external_id > 0 );
    return $external_id;
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
    // TODO(maddog) What happens (and what should happen) if the email address
    //              is used by multiple local users?  (e.g., selectRow() is
    //              here returning only one of potentially multiple rows.)
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
        throw new MWException(
          "Failed to find fresh username for '{$originalUsername}'" .
          " after {$suffix} tries." );
      }
      $username = $originalUsername . '-' . $suffix;
      $suffix += 1;
    }
    return $username;
  }


  // TODO(maddog)  Document possible throws?
  // TODO(maddog)  If this fails/throws due to a unique constraint violation,
  //               it would be nice if callers could turn that into a
  //               helpful error message.
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
      self::LINK_TABLE,
      // rows
      [ 'external_id' => $externalId,
        'local_id' => $localId, ],
      // uniqueKeys
      'external_id',
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
   * Ask Discourse to logout a user, via a request to the Discourse server's
   * log_out API endpoint.
   *
   * @param int $externalId id of the Discourse user
   *
   * @return void
   * @throws MWException if the request fails
   */
  private function logoutFromDiscourse( int $externalId ): void {
    $fullLogoutUrl = $this->config->get( 'DiscourseUrl' ) .
                   str_replace( '{id}', (string) $externalId,
                                $this->config->get( 'LogoutApiEndpoint' ) );

    $options = [
      // POST query
      CURLOPT_POST             => true,
      // Return response in return value of curl_exec()
      CURLOPT_RETURNTRANSFER  => true,
      CURLOPT_URL => $fullLogoutUrl,
      CURLOPT_HTTPHEADER      => [
        'Accept: application/json',
        "Api-Key: {$this->config->get( 'LogoutApiKey' )}",
        "Api-Username: {$this->config->get( 'LogoutApiUsername' )}",
      ],
    ];

    wfDebugLog(
      self::LOG_GROUP,
      "Sending logout request id {$externalId} to {$fullLogoutUrl}..." );

    [ $response, $httpStatus ] = self::executeCurl( $options );

    if ( ( $response === false ) || ( $httpStatus !== 200 ) ) {
      wfDebugLog( self::LOG_GROUP,
                  "Request failed.  Status {$httpStatus}.  Response " .
                  var_export( $response, true ) );
      throw new MWException( "Discourse Logout request failed." );
    }

    $response = json_decode( $response, /*as array:*/true );
    if ( ( $response['success'] ?? '' ) !== 'OK' ) {
      wfDebugLog( self::LOG_GROUP,
                  'Request failed.  Response ' . var_export( $response, true ) );
      throw new MWException( "Discourse Logout request failed." );
    }

    wfDebugLog( self::LOG_GROUP, 'Discourse logout succeeded.' );
  }


  /**
   * Execute an HTTP request using PHP's curl library.
   *
   * @param array $options array of CURLOPT_ parameters for the request
   *
   * @return array of [response, status].  response will be false if the
   *  request fails due to a curl error; otherwise it will be a string with
   *  the result of the request.  status is the integer HTTP status code
   *  (e.g., 200, 404, ...) status is only meaningful if response is not false.
   * @throws MWException for errors in preparing the curl request
   */
  private function executeCurl( array $options ) {
    try {
      $curl = curl_init();

      foreach ( $options as $option => $value ) {
        if ( !curl_setopt( $curl, $option, $value ) ) {
          throw new MWException(
              "Set curl option {$option} to {$value} failed." );
        }
      }

      $response = curl_exec( $curl );
      if ( ( $response === false ) || ( curl_errno( $curl ) !== 0 ) ) {
        $errno = curl_errno( $curl );
        $error = curl_error( $curl );
        wfLogWarning( self::LOG_GROUP . ": Curl error {$errno}:  {$error}" );
        $response = false;
      }

      $status = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
      return [ $response, $status ];
    }
    finally {
      curl_close( $curl );
    }
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
