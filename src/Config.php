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

use GlobalVarConfig;
use MediaWiki\MediaWikiServices;


class Config {

  /**
   * Prefix/suffix for our single, fat global configuration parameter
   */
  private const CONFIG_PREFIX = 'wgDiscourseSsoConsumer_';
  private const CONFIG_SUFFIX = 'Config';


  /**
   * @var Config $singleton:  Cache of singleton instance of this class
   */
  private static $singleton = null;


  /**
   * @var array $config:  This extension's configuration parameters
   */
  private $config;


  /**
   * Access this extension's configuration array.
   *
   * @return array the configuration array
   */
  public static function config() : array {
    return self::singleton()->config;
  }

  // TODO(maddog) Consider giving in to paranoia and, instead of returning
  //              the bare array, encapsulate the array in some object that
  //              provides ArrayAccess interface, or a __get() object interface,
  //              and prohibits modification of the config.  (It would need to
  //              be recursive, for the subconfigs, too.  Insert the
  //              "But why though?" meme here....)


  private static function singleton() : Config {
    if ( self::$singleton == null ) {
      self::$singleton = new Config();
    }
    return self::$singleton;
  }


  private function __construct() {
    $globalConfig = new GlobalVarConfig( self::CONFIG_PREFIX );
    $this->config = $globalConfig->get( self::CONFIG_SUFFIX );

    MediaWikiServices::getInstance()->getHookContainer()->run(
        'DiscourseSsoConsumer_Configure', [ &$this->config ], []/*options*/ );

    $this->validateConfiguration();
    // NB:  We need to ensure that schema checking/updating operations do *not*
    //      rely on the configuration in any way, because we want a sysadmin to
    //      be able to successfully update the DB immediately after installation
    //      before the extension has been configured.
    Db::ensureCurrentSchema();
  }


  private function validateConfiguration() {
    $cc = ConfigChecker::makeCheckerFor(
        $this->config, self::CONFIG_PREFIX . self::CONFIG_SUFFIX );

    $cc->hasNonemptyString( 'DiscourseUrl' );

    $ss = $cc->subconfig( 'Sso' );
    $ss->hasBool( 'Enable' );
    $ss->hasOptionalString( 'ProviderEndpoint' );
    $ss->hasOptionalString( 'SharedSecret' );
    $ss->hasBool( 'EnableSeamlessLogin' );
    $ss->hasBool( 'EnableAutoRelogin' );
    if ( $ss->get( 'Enable', false ) ) {
      $ss->isNonemptyString( 'ProviderEndpoint' );
      $ss->isNonemptyString( 'SharedSecret' );
    }

    $us = $cc->subconfig( 'User' );
    $us->hasKeywordArray( 'LinkExistingBy', [ 'email', 'username' ] );
    $us->hasBool( 'ExposeName' );
    $us->hasBool( 'ExposeEmail' );
    $us->hasOptionalArray( 'GroupMaps' );
    if ( in_array( 'email', $us->get( 'LinkExistingBy', [] ),
                   true/*strict*/ ) ) {
      $us->requireValue( 'ExposeEmail', true,
                         "'LinkExistingBy' uses 'email' method");
    }

    $ap = $cc->subconfig( 'DiscourseApi' );
    $ap->hasOptionalString( 'Username' );
    $ap->hasOptionalString( 'Key' );
    $ap->hasOptionalString( 'LogoutEndpoint' );
    $ap->hasBool( 'EnableLogout' );
    if ( $ap->get( 'EnableLogout', false ) ) {
      $ap->isNonemptyString( 'Username' );
      $ap->isNonemptyString( 'Key' );
      $ap->isNonemptyString( 'LogoutEndpoint' );
    }

    $wh = $cc->subconfig( 'Webhook' );
    $wh->hasBool( 'Enable' );
    $wh->hasOptionalString( 'SharedSecret' );
    $wh->hasStringArray( 'AllowedIpList' );
    $wh->hasKeywordArray( 'IgnoredEvents', SpecialWebhook::KNOWN_USER_EVENTS );
    if ( $wh->get( 'Enable', false ) ) {
      $wh->isNonemptyString( 'SharedSecret' );
      $wh->isNonemptyStringArray( 'AllowedIpList' );
    }

    $lo = $cc->subconfig( 'Logout' );
    $lo->hasBool( 'OfferGlobalOptionToUser' );
    $lo->hasBool( 'ForwardToDiscourse' );
    $lo->hasBool( 'HandleEventFromDiscourse' );
    if ( $lo->get( 'ForwardToDiscourse', false ) ) {
      $ss->isTrue( 'Enable',
                   "{$lo->nameOf('ForwardToDiscourse')} is enabled");
      if ( $lo->get( 'OfferGlobalOptionToUser', false ) ) {
        $ap->isTrue( 'EnableLogout',
                     "{$lo->nameOf('ForwardToDiscourse')} is enabled, and " .
                     "{$lo->nameOf('OfferGlobalOptionToUser')} is enabled" );
      }
    }
    if ( $lo->get( 'HandleEventFromDiscourse', false ) ) {
      $wh->isTrue( 'Enable',
                   "{$lo->nameOf('HandleEventFromDiscourse')} is enabled" );
    }

    $cc->throwOnErrors();
  }

}
