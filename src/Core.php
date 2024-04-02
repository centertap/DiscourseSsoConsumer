<?php
/**
 * This file is part of DiscourseSsoConsumer.
 *
 * Copyright 2024 Matt Marjanovic
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

use MediaWiki\MediaWikiServices;
use MWException;
use Title;
use User;


class Core {

  /**
   * Transform a username from SSO credentials into the appropriate form for
   * the MediaWiki site.
   *
   * @param string $ssoUsername username provided by SSO credentials
   *
   * @return string the resulting username
   * @throws MWException if $ssoUsername cannot be wikified
   */
  public static function wikifyUsername( string $ssoUsername ) : string {
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

    // TODO(maddog) Consider using UserNameUtils::getCanonical() instead of
    //              Title::makeTitleSafe().
    //
    //              We might be conflating two subtly different use-cases of
    //              this function:
    //                1) username for searching for an existing wiki user;
    //                2) username for creating a new wiki user.

    // Ask for a valid page title made from $ssoUsername (since users are just
    // pages, like every atom in the MW universe).  This will canonicalize the
    // name according to how the MW site has been configured.
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
  public static function wikifyName( string $ssoName ) : string {
    // Both MediaWiki and Discourse treat the full-/real-name as an opaque
    // string, so no tranformation is necessary except for hiding.
    if ( Config::config()['User']['ExposeName'] === true ) {
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
  public static function wikifyEmail( string $ssoEmail ) : string {
    // Both MediaWiki and Discourse treat the email address as an opaque
    // string, so no tranformation is necessary except for hiding.
    if ( Config::config()['User']['ExposeEmail'] === true ) {
      return $ssoEmail;
    }
    return '';
  }


  /**
   * Set a wiki user's group memberships, based on SSO credentials.
   *
   * If a GroupMaps has been configured, (re)populates the group membership
   * of $user according to the 'groups', 'is_admin', and 'is_moderator'
   * elements retrieved from the Discourse SSO credentials.
   *
   * @param User $user the user to be updated
   * @param array $credentials an array bearing (at least) three items:
   *         'groups' => list of strings of Discourse group names
   *         'is_admin' => bool, true if user is a Discourse admin
   *         'is_moderator' => bool, true if user a Discourse moderator
   *
   * @return void This method returns no value.
   */
  public static function populateGroupsForUser(
      User $user, array $credentials ) : void {
    $groupMaps = Config::config()['User']['GroupMaps'];
    if ( !$groupMaps ) {
      Util::debug( 'No GroupMaps configured.' );
      return;
    }

    Util::debug(
      "Populating groups for user #{$user->getId()} '{$user->getName()}'..." );

    // Get the user's Discourse groups from the SSO credentials.
    $userDiscourseGroups = $credentials['groups'];
    // If a moderator or admin bits are set, add appropriate special tag groups.
    if ( $credentials['is_admin'] ) {
      $userDiscourseGroups[] = '@ADMIN@';
    }
    if ( $credentials['is_moderator'] ) {
      $userDiscourseGroups[] = '@MODERATOR@';
    }
    Util::debug( 'User\'s discourse groups: ' .
                 var_export( $userDiscourseGroups, true ) );

    // Loop over the group map, collecting wiki groups to be added or removed.
    $toAdd = [];
    $toRemove = [];
    foreach ( $groupMaps as $mappingWikiGroup => $mappingDiscourseGroups ) {
      if ( array_intersect( $userDiscourseGroups,
                            $mappingDiscourseGroups ) === [] ) {
        $toRemove[] = $mappingWikiGroup;
      } else {
        $toAdd[] = $mappingWikiGroup;
      }
    }

    // Since addGroup() and removeGroup() are fairly heavyweight operations,
    // which call hooks and do database things even if membership is not going
    // to change,  we call them only if we need to modify the status of
    // membership in a group.

    $currentGroups = MediaWikiServices::getInstance()->getUserGroupManager()
        ->getUserGroups( $user );
    $toAdd = array_diff( $toAdd, $currentGroups );
    $toRemove = array_intersect( $toRemove, $currentGroups );
    foreach ( $toAdd as $group ) {
      Util::debug( "Adding membership to '{$group}'." );
      MediaWikiServices::getInstance()->getUserGroupManager()
          ->addUserToGroup( $user, $group,
                            null/*expiry*/, true/*allowUpdate*/ );
      // TODO(maddog) Should we check the return value?  Hmm...
      //              addUserToGroup() returns false if it did not affect any
      //              user_groups rows in the DB... but, that could happen if
      //              an onUserAddGroup() hook is attached and has decided not
      //              allow the user to be added to the group.  So, here we
      //              cannot distinguish a failure from an intentional override.
    }
    foreach ( $toRemove as $group ) {
      Util::debug( "Removing membership to '{$group}'." );
      MediaWikiServices::getInstance()->getUserGroupManager()
          ->removeUserFromGroup( $user, $group );
      // TODO(maddog) Should we check the return value?  Hmm...
      //              Same issue as above, but with the onUserRemoveGroup() hook.
    }

    // TODO(maddog) Should some kind of cache-flush happen here, or in the
    //              caller?  (E.g., PluggableAuthPopulateGroups hook probably
    //              does not need a flush.)
  }


  /**
   * Look up the wiki-user linked to a discourse-id and return wiki-info for
   * the user, updated by the data in SSO credentials.
   *
   * @param array $credentials SSO-credentials array
   *
   * @return ?array null if the discourse user in $credentials is not
   *  already linked to a wiki user; otherwise, an array of wiki info
   *  that contains updates from fields in the discourse credentials
   */
  public static function makeUpdatedInfoForAlreadyLinkedUser(
      array $credentials ) : ?array {
    Util::debug( __METHOD__ );
    // Lookup discourse_id in our registry.
    $linkLookup = Db::lookupDiscourseId( $credentials['discourse_id'] );
    Util::debug(
        'Lookup discourse_id in database: ' . var_export( $linkLookup, true ) );
    if ( $linkLookup === null ) { return null; }

    // Yay, we already have a link to a wiki user.
    Util::debug( "discourse_id '{$credentials['discourse_id']}' is mapped to " .
                 "wiki id '{$linkLookup->wiki_id}'." );
    // We (must) retain the existing wiki id and username, but we provide
    // the possibly-updated real name and email from Discourse credentials.
    return [ 'id' => $linkLookup->wiki_id,
             'username' => $linkLookup->wiki_username,
             'realname' => self::wikifyName( $credentials['name'] ),
             'email' => self::wikifyEmail( $credentials['email'] ),
             ];
  }
  // TODO(maddog) PluggableAuth will take care of updating the user's
  //              realname and email address (if we provide the new
  //              versions).  Changing a *username*, however, is a much
  //              more involved process (i.e., see Extension:Renameuser),
  //              so we don't try to do that yet.  In the meantime, if
  //              the Discourse username has diverged from the MediaWiki
  //              username, we should produce some kind of warning about
  //              the mismatch.  This may require tracking the Discourse
  //              username in our little DB table.


  /**
   * Handle SSO credentials bearing a Discourse user id which has not yet
   * been associated to a wiki id.  Depending on configuration, this may
   * involve attempting to match to an existing wiki user by username or
   * email.
   *
   * If no matching wiki id is found, a new username (based on the Discourse
   * username) will be constructed and returned (with a null wiki id) to
   * cause a new wiki account to be created.
   *
   * @param array $ssoCredentials the SSO credentials
   *
   * @return array bearing wiki account info for the authenticated SSO user
   * @throws MWException if wiki account info cannot be determined/created
   */
  public static function handleUnknownUser( array $ssoCredentials ) : array {
    Util::debug( __METHOD__ );
    $discourseId = $ssoCredentials['discourse_id'];
    Util::debug( "discourse_id '{$discourseId}' is new to us." );
    $wikiInfo = [
      'id' => null,
      'username' => self::wikifyUsername( $ssoCredentials['username'] ),
      'realname' => self::wikifyName( $ssoCredentials['name'] ),
      'email' => self::wikifyEmail( $ssoCredentials['email'] ),
    ];

    $found = null;
    foreach ( Config::config()['User']['LinkExistingBy'] as $method ) {
      Util::debug( "Trying LinkExistingBy '{$method}'..." );
      if ( $method === 'username' ) {
        // Attempt to find a user with "same" username as in credentials.
        $found = Db::findUserByUsername( $wikiInfo['username'] );
      } elseif ( $method === 'email' ) {
        // Attempt to find a user with same email address as in credentials.
        // (...but, only if we are allowed to use email address for anything.)
        if ( Config::config()['User']['ExposeEmail'] !== true ) {
          // The email address should not be an empty string at this point,
          // but if it were, it would be dangerous to match it againts any
          // wiki user that also has an empty-string email address.  So, do not
          // let that happen.
          Util::insist( $wikiInfo['email'] !== '' );
          $found = Db::findUserByEmail( $wikiInfo['email'] );
        } else {
          Util::debug( 'LinkExistingBy:Email requires ExposeEmail:true.' );
        }
      } else {
        Util::debug( "Ignoring unknown 'LinkExistingBy' method: '{$method}'." );
      }
      if ( $found ) {
        Util::debug(
          "Linking discourse user #{$discourseId} '{$ssoCredentials['username']}'"
          . " to wiki user #{$found->wiki_id} '{$found->wiki_username}'." );
        $wikiInfo['id'] = $found->wiki_id;
        $wikiInfo['username'] = $found->wiki_username;

        // TODO(maddog) Our schema prevents the possibility of two Discourse
        //              users becoming linked to the same MW user, via a
        //              uniqueness constraint, but should we try to discover
        //              this case before calling updateIdLinkage()?
        Db::updateIdLinkage( $discourseId, $found->wiki_id );

        // $method has succeeded, so don't try any more methods.
        break;
      }
    }

    if ( !$wikiInfo['id'] ) {
      // Still no link?  Then we need to create a new MW user.  We only
      // supply the user info; AuthManager machinery will decide whether or
      // not user creation is allowed and take care of it.  If/when a new
      // user is auto-created, saveExtraAttributes() will be automagically
      // called, and that is when we will insert the new link record in our
      // table.
      //
      // All we need to do is (try to) make sure we supply an available new
      // username.
      $wikiInfo['username'] = self::ensureFreshUsername( $wikiInfo['username'] );
      Util::debug(
        "New wiki user '{$wikiInfo['username']}' needs to be created." );
    }

    return $wikiInfo;
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
  public static function ensureFreshUsername( string $originalUsername )
    : string {
    $suffix = 1;
    $username = $originalUsername;
    $uidLookup = MediaWikiServices::getInstance()->getUserIdentityLookup();
    // NB: Default READ_NORMAL of getUserIdentityByName() is sufficient,
    //     because, as stated at our one call site, "All we need to do is
    //     (try to) make sure we supply an available new username."
    while ( $uidLookup->getUserIdentityByName( $username ) !== null ) {
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

}
