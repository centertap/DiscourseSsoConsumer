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

use DeferredUpdates;
use MWException;
use MWTimestamp;
use Wikimedia\ScopedCallback;

// TODO(maddog) Go through the whole extension and make sure DB access is
//              principled and compatible with a load-balanced/clustered setup.
//
//              In particular, we need to ensure that DB_MASTER is used (and
//              used consistently) when it is needed, and not used when not.
//              In addition to the choices we make in this file, other things
//              that access the DB (e.g., User::idFromName()), won't
//              necessarily make the same choices (by default)!
//
//              This all works fine when MASTER and REPLICA are the same, but
//              could have heisenbugs in a clustered system.
//
//              - Look at dao/IDBAcessObject.php.
//              - Look at dao/DBAcessObjectUtils.php.
//              - Look at the use of "$flags" parameters in MW core.
//              - Consider using READ_EXCLUSIVE flags in (some) Db::* operations.
//              - Look at User::getInstanceForUpdate().

// TODO(maddog) Rewrite wfGetDB() with more modern language, e.g.,
//               $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
//               $dbw = $lb->getConnectionRef( DB_PRIMARY );
//
//              Look at DBAccessObjectUtils::getDBOptions()!

// TODO(maddog) Use modern SelectQueryBuilder
//     https://www.mediawiki.org/wiki/Manual:Database_access#SelectQueryBuilder


class Db {

  /**
   * Prefix for keys for database locks on Discourse id's.
   */
  public const LOCK_KEY_PREFIX = 'DiscourseSsoConsumer/DiscourseIdLock:';


  /**
   * Timeout duration for acquisition of database locks on Discourse id's.
   */
  public const LOCK_TIMEOUT = 5; // seconds


  /**
   * Ensure that the database's schema will work with this code.
   *
   * @return void This function returns no value.
   *
   * @throws MWException if fails to find a fresh username
   */
  public static function ensureCurrentSchema() {
    // Ensure that the database's schema will work with this code.
    $currentDbSchema = Schema::fetchSchemaVersion( wfGetDB( DB_REPLICA ) );
    if ( $currentDbSchema === null ) {
      throw new MWException(
          "DB does not have our schema at all.  " .
          "Did you forget to run 'maintenance/update.php'?" );
    } elseif ( $currentDbSchema !== Schema::SCHEMA_VERSION ) {
      throw new MWException(
          "DB has schema {$currentDbSchema}, but this code requires schema " .
          Schema::SCHEMA_VERSION .
          ".  Did you forget to run 'maintenance/update.php'?" );
    }
  }


  /**
   * Acquire a database lock for a Discourse id.
   *
   * This function blocks until it acquires the lock.  If a timeout occurs
   * (after self::LOCK_TIMEOUT seconds) before the lock is acquired, a
   * MWException will be thrown.  If the lock is successfully acquired,
   * then the current database transaction will be flushed to release any
   * potentially stale REPEATABLE-READ snapshot of the data.
   *
   * The lock will be automatically released near the end of the current
   * web request, after any changes to the database have been committed.
   *
   * @param int $discourse_id the id that the lock controls
   *
   * @return void This function returns no value.
   *
   * @throws MWException if the lock could not be acquired.
   */
  public static function acquireLockOnDiscourseId( int $discourse_id ) : void {
    $dbw = wfGetDB( DB_MASTER );
    $key = self::LOCK_KEY_PREFIX . (string)($discourse_id);
    $lock = $dbw->getScopedLockAndFlush( $key, __METHOD__, self::LOCK_TIMEOUT );

    if ( $lock === null ) {
      throw new MWException(
          "Failed to acquire scoped lock on discourse-id {$discourse_id}." );
    }

    // Binding the lock's ScopedCallback to a deferred-update callback ensures
    // that it will remain in-scope until at least when the deferred-update is
    // executed (after PreOutputCommit, but before output is released to the
    // client).  Calling consume() ensures that the lock is definitely released
    // when the deferred-update is executed.
    DeferredUpdates::addCallableUpdate(
        function () use ( $lock ) { ScopedCallback::consume( $lock ); },
        DeferredUpdates::PRESEND );
  }


  /**
   * Given a Discourse user id, find the associated wiki id and username.
   *
   * @param int $discourseId the target Discourse id
   *
   * @return ?object If $discourseId is known, returns an object bearing members
   *          discourse_id, wiki_id, and wiki_username; otherwise, returns null.
   */
  public static function lookupDiscourseId( int $discourseId ) : ?object {
    $dbr = wfGetDB( DB_MASTER );
    $row = $dbr->selectRow(
      // tables
      [ 'dsc' => Schema::LINK_TABLE, 'u' => 'user' ],
      // columns/variables
      [ 'discourse_id' => 'dsc.discourse_id',
        'wiki_id' => 'u.user_id',
        'wiki_username' => 'u.user_name' ],
      // conditions (WHERE)
      [ 'dsc.discourse_id' => $discourseId, ],
      // fname
      __METHOD__,
      // options
      [],
      // join conditions
      [ 'u' => [ 'JOIN', [ 'u.user_id=dsc.wiki_id' ] ] ]
    );
    '@phan-var \stdClass|false $row'; // NB: selectRow() has mistyped @return.
    if ( !$row ) {
      return null;
    }
    # DB results appear to be strings even if a column is typed as integer.
    $row->discourse_id = (int)$row->discourse_id;
    $row->wiki_id = (int)$row->wiki_id;
    Util::insist( $row->discourse_id > 0 );
    Util::insist( $row->wiki_id > 0 );
    Util::insist( strlen( $row->wiki_username ) > 0 );
    return $row;
  }


  /**
   * Given a MediaWiki user id, find the associated Discourse user id.
   *
   * @param int $wikiId the target wiki id
   *
   * @return ?int the associated Discourse id if $wikiId is known, else null
   */
  public static function lookupDiscourseIdByWikiId( int $wikiId ) : ?int {
    $dbr = wfGetDB( DB_MASTER );
    $row = $dbr->selectRow(
      // tables
      [ 'dsc' => Schema::LINK_TABLE ],
      // columns/variables
      [ 'discourse_id' => 'dsc.discourse_id',
        'wiki_id' => 'dsc.wiki_id' ],
      // conditions (WHERE)
      [ 'dsc.wiki_id' => $wikiId, ],
      // fname
      __METHOD__,
      // options
      [],
      // join conditions
      []
    );
    '@phan-var \stdClass|false $row'; // NB: selectRow() has mistyped @return.
    if ( !$row ) {
      return null;
    }
    # DB results appear to be strings even if a column is typed as integer.
    $discourse_id = (int)$row->discourse_id;
    Util::insist( $discourse_id > 0 );
    return $discourse_id;
  }


  /**
   * Find a Mediawiki user with the given email address.
   *
   * @param string $email the target email address
   *
   * @return ?object bearing members wiki_id and wiki_username
   *                if $email is known, else null
   */
  public static function findUserByEmail( string $email ) : ?object {
    // find user record with matching email address
    if ( $email === '' ) {
      Util::debug( 'Email address is empty string, skipping.' );
      return null;
    }
    // TODO(maddog) What happens (and what should happen) if the email address
    //              is used by multiple wiki users?  (e.g., selectRow() is
    //              here returning only one of potentially multiple rows.)
    $dbr = wfGetDB( DB_MASTER );
    $row = $dbr->selectRow(
      // tables
      [ 'u' => 'user' ],
      // columns/variables
      [ 'wiki_id' => 'u.user_id',
        'wiki_username' => 'u.user_name' ],
      // conditions (WHERE)
      [ 'u.user_email' => $email, ],
      // fname
      __METHOD__,
      // options
      [],
      // join conditions
      []
    );
    '@phan-var \stdClass|false $row'; // NB: selectRow() has mistyped @return.
    if ( !$row ) {
      return null;
    }
    # DB results appear to be strings even if a column is typed as integer.
    $row->wiki_id = (int)$row->wiki_id;
    Util::insist( $row->wiki_id > 0 );
    Util::insist( strlen( $row->wiki_username ) > 0 );
    return $row;
  }


  /**
   * Find a Mediawiki user with the given username.
   *
   * @param string $username the target username
   *
   * @return ?object bearing members wiki_id and wiki_username (=== $username)
   *                if $username is known, else null
   */
  public static function findUserByUsername( string $username ) : ?object {
    // find user record with matching canonicalized username
    if ( $username === '' ) {
      Util::debug( 'Username is empty string, skipping.' );
      return null;
    }
    $dbr = wfGetDB( DB_MASTER );
    $row = $dbr->selectRow(
      // tables
      [ 'u' => 'user' ],
      // columns/variables
      [ 'wiki_id' => 'u.user_id',
        'wiki_username' => 'u.user_name' ],
      // conditions (WHERE)
      [ 'u.user_name' => $username, ],
      // fname
      __METHOD__,
      // options
      [],
      // join conditions
      []
    );
    '@phan-var \stdClass|false $row'; // NB: selectRow() has mistyped @return.
    if ( !$row ) {
      return null;
    }
    # DB results appear to be strings even if a column is typed as integer.
    $row->wiki_id = (int)$row->wiki_id;
    Util::insist( $row->wiki_id > 0 );
    Util::insist( $row->wiki_username === $username );
    return $row;
  }


  // TODO(maddog)  Document possible throws?
  // TODO(maddog)  If this fails/throws due to a unique constraint violation,
  //               it would be nice if callers could turn that into a
  //               helpful error message.
  /**
   * Link a Discourse id to a MediaWiki id in the database.
   *
   * @param int $discourseId the Discourse id
   * @param int $wikiId the wiki id
   *
   * @return void  This method does not return a value.
   */
  public static function updateIdLinkage( int $discourseId, int $wikiId )
    : void {
    Util::debug(
      "Linking discourse-id '{$discourseId}' to wiki-id '{$wikiId}'." );
    $dbw = wfGetDB( DB_MASTER );
    $dbw->upsert(
      // table
      Schema::LINK_TABLE,
      // rows
      [ 'discourse_id' => $discourseId,
        'wiki_id' => $wikiId, ],
      // uniqueKeys
      'discourse_id',
      // set
      [ 'wiki_id' => $wikiId, ],
      __METHOD__
    );
    // TODO(maddog)  Add/update a timestamp column.
  }

  // TODO(maddog) If the Discourse URL is changed (e.g., connect somewhere
  //              else), shouldn't all the link and user-data tables be
  //              invalidated somehow?
  //              Should the Discourse URL be stored with each link record?
  //              Should there be a way to purge known-bogus/stale records?


  // TODO(maddog) Figure out how to get subsecond timestamps into the DB.
  //
  // Timestamp formats (used in REL1_39):
  //              ...see  includes/libs/rdbms/dbal/TimestampType.php
  //
  //  sqlite:
  //    ? text as ISO-8601  "YYYY-MM-DD HH:MM:SS.SSS" (subsecond precision)
  //    ? integer as seconds relative to unix epoch
  //    - BLOB
  //  postgres:
  //    - TIMESTAMPTZ
  //  mysql:
  //    ? currently, binary(14) or varbinary(14) (when nullable)
  //    ? eventually, TIMESTAMPTZ (see maintenance/tables.sql)
  //    - BINARY(14)

  // TODO(maddog)  Document possible throws?
  // TODO(maddog)  If this fails/throws due to a unique constraint violation,
  //               it would be nice if callers could turn that into a
  //               helpful error message.
  /**
   * Store Discourse user data, as a JSON blob.
   *
   * @param object $userRecord the user record, as an object
   * @param string $updateEvent the name/type of the event causing the update,
   *    or null if not caused by a webhook event
   * @param int $updateEventId the id of the event causing the update,
   *    or null if not caused by a webhook event
   * @param MWTimestamp $now timestamp for the user record
   *
   * @return void  This method does not return a value.
   */
  public static function updateUserRecord( object $userRecord,
                                           string $updateEvent,
                                           int $updateEventId,
                                           MWTimestamp $now) : void {
    Util::debug( "Storing user record for discourse-id '{$userRecord->id}'" );
    $discourseId = $userRecord->id;
    $jsonUserBlob = Util::encodeObjectAsJson( $userRecord );

    $dbw = wfGetDB( DB_MASTER );
    $dbw->upsert(
      // table
      Schema::USER_TABLE,
      // rows
      [ 'discourse_id' => $discourseId,
        'user_json' => $jsonUserBlob,
        'last_event' => $updateEvent,
        'last_event_id' => $updateEventId,
        'last_update' => $dbw->timestamp($now), ],
      // uniqueKeys
      'discourse_id',
      // set
      [ 'user_json' => $jsonUserBlob,
        'last_event' => $updateEvent,
        'last_event_id' => $updateEventId,
        'last_update' => $dbw->timestamp($now), ],
      __METHOD__
    );
  }


  /**
   * Fetch Discourse user data.
   *
   * @param int $discourseId the Discourse id to lookup
   *
   * @return ?object If $discourseId is known, returns an object bearing members
   *  discourse_id, user_record, last_update, last_event, last_event_id;
   *  otherwise returns null.
   */
  public static function fetchUserRecord( int $discourseId ) : ?object {
    Util::debug( "Fetching user record for discourse-id '{$discourseId}'" );

    $dbr = wfGetDB( DB_MASTER );
    $row = $dbr->selectRow(
      // tables
      [ 'dsu' => Schema::USER_TABLE ],
      // columns/variables
      [ 'discourse_id' => 'dsu.discourse_id',
        'user_record' => 'dsu.user_json',
        'last_update' => 'dsu.last_update',
        'last_event' => 'dsu.last_event',
        'last_event_id' => 'dsu.last_event_id',
        ],
      // conditions (WHERE)
      [ 'dsu.discourse_id' => $discourseId, ],
      // fname
      __METHOD__,
      // options
      [],
      // join conditions
      []
    );
    '@phan-var \stdClass|false $row'; // NB: selectRow() has mistyped @return.
    if ( !$row ) {
      return null;
    }
    # Decode/clean-up results.
    # (DB results appear to be strings even if a column is typed as integer.)
    $row->discourse_id = (int)$row->discourse_id;
    $row->user_record = Util::decodeJsonAsObject($row->user_record);
    $row->last_update = new MWTimestamp($row->last_update);
    // $row->last_event remains string.
    $row->last_event_id = (int)$row->last_event_id;

    return $row;
  }


  /**
   * Fetch Discourse user data, given a wiki id.
   *
   * @param int $wikiId the wiki id to lookup
   *
   * @return ?object If $wikiId is known, returns an object bearing members
   *  discourse_id, user_record, last_update, last_event, last_event_id;
   *  otherwise returns null.
   */
  public static function fetchUserRecordByWikiId( int $wikiId ) : ?object {
    Util::debug( "Fetching user record for wiki-id '{$wikiId}'" );

    $dbr = wfGetDB( DB_REPLICA );
    $row = $dbr->selectRow(
      // tables
      [ 'dsl' => Schema::LINK_TABLE,
        'dsu' => Schema::USER_TABLE ],
      // columns/variables
      [ 'discourse_id' => 'dsu.discourse_id',
        'user_record' => 'dsu.user_json',
        'last_update' => 'dsu.last_update',
        'last_event' => 'dsu.last_event',
        'last_event_id' => 'dsu.last_event_id',
        ],
      // conditions (WHERE)
      [ 'dsl.wiki_id' => $wikiId, ],
      // fname
      __METHOD__,
      // options
      [],
      // join conditions
      [ 'dsu' => [ 'JOIN', [ 'dsu.discourse_id=dsl.discourse_id' ] ] ]
    );
    '@phan-var \stdClass|false $row'; // NB: selectRow() has mistyped @return.
    if ( !$row ) {
      return null;
    }
    # Decode/clean-up results.
    # (DB results appear to be strings even if a column is typed as integer.)
    $row->discourse_id = (int)$row->discourse_id;
    // NB: phan can't possibly figure what selectRow() returns inside, but we
    //     are confident it is just a string.
    $user_record_string = $row->user_record;
    '@phan-var string $user_record_string';
    $row->user_record = Util::decodeJsonAsObject( $user_record_string );
    $row->last_update = new MWTimestamp($row->last_update);
    // $row->last_event remains string.
    $row->last_event_id = (int)$row->last_event_id;

    return $row;
  }

}
