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

use MWException;


class ConfigChecker {

  /**
   * The configuration being checked
   */
  private array $config;

  /**
   * String naming the configuration being checked
   */
  private string $base;

  /**
   * Buffer to accumulate configuration errors detected during checking
   */
  private array $errors;

  /**
   * Top-level/root ConfigChecker instance.
   *
   * Errors detected by subconfig checkers will be accumulated by the
   * root checker.
   */
  private ConfigChecker $root;


  /**
   * Construct a new ConfigChecker to validate a configuration array.
   *
   * @param array $config the configuration array to be checked
   * @param string $base the descriptive base name of the configuration,
   *                     to be displayed in error messages, e.g., the name
   *                     of the configuration variable holding this array
   *
   * @return ConfigChecker a new instance of ConfigChecker
   */
  public static function makeCheckerFor( array $config,
                                         string $base ) : ConfigChecker {
    return new ConfigChecker( $config, $base, null );
  }


  /**
   * Check if any errors in the configuration have been detected.
   *
   * @return void This function returns no value.
   *
   * @throws MWException if any errors have been detected.
   */
  public function throwOnErrors() : void {
    if ( count( $this->errors ) === 0 ) {
      return;
    }
    throw new MWException(
        "DiscourseSsoConsumer has configuration errors:\n  * " .
        implode( "\n  * ", $this->errors ) );
  }


  /**
   * Get a parameter value from the configuration.
   *
   * @param string $p the name of the parameter.
   * @param mixed $fallback value to return if no such parameter exists in
   *                        the configuration
   *
   * @return mixed the parameter value, or the fallback value
   */
  public function get( string $p, $fallback ) /*: mixed*/ {
    if ( array_key_exists( $p, $this->config ) ) {
      return $this->config[$p];
    }
    return $fallback;
  }


  /**
   * Check that the parameter has a specific value.
   *
   * @param string $p the parameter name
   * @param mixed $value the required value
   * @param string $reason description of why this is being enforced
   *
   * @return void returns nothing
   */
  public function requireValue( string $p,
                                /*mixed*/ $value, string $reason ) : void {
    $param = $this->config[$p];
    if ( $param !== $value ) {
        $this->recordError( "{$reason}; {$this->nameOf($p)} must be set to " .
                            var_export( $value, true ) . "." );
    }
  }


  /**
   * Check that a subconfiguration array exists and return a ConfigChecker
   * assigned to validating it.
   *
   * @param string $p parameter name of the subconfiguration
   *
   * @return ConfigChecker for checking the subconfiguration
   *
   * NB: If $p does not exist in the current configuration, the returned
   *     ConfigChecker will simply be assigned to inspect an empty array.
   */
  public function subconfig( string $p ) : ConfigChecker {
    $sub = [];
    if ( $this->exists( $p, 'subconfig array' ) ) {
      $sub = $this->config[$p];
      if ( !is_array( $sub ) ) {
        $this->recordError( "{$this->nameOf($p)} must be array." );
        $sub = [];
      }
    }
    return new ConfigChecker( $sub, $this->base . "['{$p}']", $this->root );
  }


  /**
   * Check for the existence of a boolean parameter.
   *
   * @param string $p the parameter name
   * @return void returns nothing
   */
  public function hasBool( string $p ) : void {
    if ( $this->exists( $p, 'bool' ) ) {
      if ( !is_bool( $this->config[$p] ) ) {
        $this->recordError( "{$this->nameOf($p)} must be bool." );
      }
    }
  }


  /**
   * Check for the existence of a parameter containing a string or null.
   *
   * @param string $p the parameter name
   * @return void returns nothing
   */
  public function hasOptionalString( string $p ) : void {
    if ( $this->exists( $p, 'string' ) ) {
      if ( ( !is_null ($this->config[$p] ) &&
             !is_string( $this->config[$p] ) ) ) {
        $this->recordError( "{$this->nameOf($p)} must be string or null." );
      }
    }
  }


  /**
   * Check that an existing parameter contains a non-empty string.
   *
   * @param string $p the parameter name
   * @return void returns nothing
   */
  public function isNonemptyString( string $p ) : void {
    $param = $this->config[$p];
    if ( !is_string( $param ) || (strlen( $param ) <= 0 ) ) {
      $this->recordError( "{$this->nameOf($p)} must be non-empty string." );
    }
  }


  /**
   * Check for the existence of a parameter with a non-empty string.
   *
   * @param string $p the parameter name
   * @return void returns nothing
   */
  public function hasNonemptyString( string $p ) : void {
    if ( $this->exists( $p, 'string' ) ) {
      $param = $this->config[$p];
      if ( !is_string( $param ) ) {
        $this->recordError( "{$this->nameOf($p)} must be string." );
      } elseif ( strlen( $param ) <= 0 ) {
        $this->recordError( "{$this->nameOf($p)} must be non-empty." );
      }
    }
  }


  /**
   * Check for the existence of a parameter with a sequential array,
   * in which all values belong to a set of accepted keywords.
   *
   * @param string $p the parameter name
   * @param array $validKeywords the list of acceptable values
   * @return void returns nothing
   */
  public function hasKeywordArray( string $p, array $validKeywords ) : void {
    if ( $this->exists( $p, 'array' ) ) {
      $param = $this->config[$p];
      if ( !is_array( $param ) ) {
        $this->recordError( "{$this->nameOf($p)} must be array." );
      } elseif ( !empty( array_diff( $param, $validKeywords ) ) ) {
        $this->recordError(
            "{$this->nameOf($p)} may only contain values from {"
            . implode(', ', $validKeywords) . '}.' );
      }
    }
  }


  /**
   * Check for the existence of a parameter containing an array or null.
   *
   * @param string $p the parameter name
   * @return void returns nothing
   */
  public function hasOptionalArray( string $p ) : void {
    if ( $this->exists( $p, 'array' ) ) {
      $param = $this->config[$p];
      if ( ( $param !== null ) && ( !is_array( $param ) ) ) {
        $this->recordError( "{$this->nameOf($p)} must be array or null." );
      }
    }
  }


  /**
   * Check for the existence of a parameter containing an array of strings.
   *
   * @param string $p the parameter name
   * @return void returns nothing
   */
  public function hasStringArray( string $p ) : void {
    if ( $this->exists( $p, 'array' ) ) {
      $param = $this->config[$p];
      if ( !is_array( $param ) ) {
        $this->recordError( "{$this->nameOf($p)} must be array." );
      }
      foreach ( $param as $item ) {
        if ( !is_string( $item ) ) {
          $this->recordError( "{$this->nameOf($p)} must be array of strings." );
        }
      }
    }
  }


  /**
   * Check that an existing parameter contains an non-empty array of strings.
   *
   * @param string $p the parameter name
   * @return void returns nothing
   */
  public function isNonemptyStringArray( string $p ) : void {
    $param = $this->config[$p];
    if ( !is_array( $param ) || ( count( $param ) <= 0 ) ) {
      $this->recordError( "{$this->nameOf($p)} must be non-empty array." );
    }
  }


  /**
   * Check that an existing parameter is equal to true.
   *
   * @param string $p the parameter name
   * @param string $reason why the parameter needs to be true
   * @return void returns nothing
   */
  public function isTrue( string $p, string $reason = 'reasons' ) : void {
    $param = $this->config[$p];
    if ( $param !== true ) {
      $this->recordError( "{$this->nameOf($p)} must be true, because {$reason}." );
    }
  }


  /**
   * Produce the full name of an parameter, including the names
   * of the subconfigurations containing it.
   *
   * @param string $p the identifier for the parameter
   * @return string
   */
  public function nameOf( string $p ) : string {
    return '$' . "{$this->base}['{$p}']";
  }


  private function __construct( array $config, string $base,
                               ?ConfigChecker $root ) {
    $this->config = $config;
    $this->base = $base;
    $this->errors = [];
    // NB:  Workaround a phan quirk involving typing of $this...
    $myself = $this;
    '@phan-var ConfigChecker $myself';
    $this->root = $root ?? $myself;
  }

  // TODO(maddog) Consider implementing a __destruct() that somehow
  //              ensures that throwOnErrors() has been called (or,
  //              calls it itself?).

  private function recordError( string $message ) : void {
    $this->root->errors[] = $message;
  }

  /**
   * Check if the parameter exists, and record an error if not.
   *
   * @param string $p the name of the parameter
   * @param string $type description of the expected type of the parameter,
   *                     shown in the error message if the parameter does not
   *                     exist
   *
   * @return bool true if the parameter key exists, false otherwise
   */
  private function exists( string $p, string $type ) : bool {
    if ( !array_key_exists( $p, $this->config ) ) {
      $this->recordError( "Required {$type} {$this->nameOf($p)} is missing." );
      return false;
    }
    return true;
  }

}
