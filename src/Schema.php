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

use DatabaseUpdater;
use Wikimedia\Rdbms\IMaintainableDatabase;
use MWException;


class Schema { // TODO(maddog) implements LoadExtensionSchemaUpdatesHook {

  /**
   * Version of our schema required by this code
   */
  public const SCHEMA_VERSION = 8;

  /**
   * Name of our metadata table in the database
   */
  public const META_TABLE = 'discourse_sso_consumer_meta';

  /**
   * Name of our id-linkage table in the database
   */
  public const LINK_TABLE = 'discourse_sso_consumer_link';


  /**
   * Name of our Discourse user data cache table in the database
   */
  public const USER_TABLE = 'discourse_sso_consumer_discourse_user';


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
      $vNNNN = 'v0008';
      Util::insist( self::SCHEMA_VERSION === (int) substr( $vNNNN, 1) );
      self::installSchemaVnnnn( $vNNNN, $updater );

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
        case 3:
          self::applySchemaPatchVnnnn( 'v0004', $updater );
          self::applySchemaPatchVnnnn( 'v0005', $updater );
          self::applySchemaPatchVnnnn( 'v0006', $updater );
          self::applySchemaPatchVnnnn( 'v0007', $updater );
        case 7:
          self::applySchemaPatchVnnnn( 'v0008', $updater );
          // (only break here, at the end of the chain)
          break;
        default:
          Util::unreachable();
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
   * @param IMaintainableDatabase $dbr the database to query
   *
   * @return ?int the version number of the schema, or null if no schema has
   *  been installed
   *
   * @throws MWException if a version should exist but cannot be determined
   */
  public static function fetchSchemaVersion( IMaintainableDatabase $dbr ): ?int {
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
    '@phan-var \stdClass|false $row'; // NB: selectRow() has mistyped @return.
    if ( !$row ) {
      throw new MWException(
          "Metadata table is broken:  no entry for 'schemaVersion'!" );
    }
    Util::insist( $row->key === 'schemaVersion' );
    Util::insist( is_numeric( $row->value ) );
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
    $patchFilePath = self::findPatchPath( "schema-{$vnnnn}",
                                          $updater->getDb()->getType() );
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
    $patchFilePath = self::findPatchPath( "patch-{$vnnnn}",
                                          $updater->getDb()->getType() );
    $updater->addExtensionUpdate(
        [ 'applyPatch', $patchFilePath, true,
          "Patching DiscourseSsoConsumer schema to {$vnnnn} via '{$patchFilePath}'" ] );
  }


  /**
   * Find the most appropriate path for a patch file.  If a DB-specific path
   * exists, choose that; otherwise select a generic path.
   *
   * @param string $base base-name for the patch file
   * @param string $dbType name of the database type
   *
   * @return string absolute path to the patch file
   */
  private static function findPatchPath( string $base, string $dbType
                                         ) : string {
    $specific = __DIR__ . "/../sql/{$base}-{$dbType}.sql";
    $general = __DIR__ . "/../sql/{$base}.sql";
    if ( file_exists( $specific ) ) {
      return $specific;
    }
    return $general;
  }

}
