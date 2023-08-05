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


class Util {

  /**
   * Name of log-group we use for debug logging
   */
  private const LOG_GROUP = 'DiscourseSsoConsumer';


  /**
   * Send a redirect response to the browser and exit request handling.
   *
   * @param string $url the URL to redirect to
   * @param array $params parameters (as key:value pairs) to attach to the URL
   *
   * This method does not return.
   */
  // TODO(maddog) In PHP-8.1, give this the return type "never".
  public static function redirectToUrlAndExit( string $url, array $params )
    : void {
    $complete_url = $url . '?' . http_build_query( $params );

    header( 'Location: ' . $complete_url );
    exit( 0 );
  }
  // TODO(maddog) This yields a very "abrupt" redirect --- it just exits,
  //              and that results in DB ROLLBACK on the way out the door.
  //              It seems to work, but... couldn't it cause problems for
  //              someone somewhere?  (If there is work that needs to be
  //              committed, or deferred tasks?)
  //
  //              Yet, we do want to interrupt the normal flow and avoid
  //              any other subsequent stuff.
  //
  //              Could we acheive our goals using MW OutputPage machinery
  //              and throwing ErrorPageError or something instead?


  /**
   * Construct a random nonce.
   *
   * @return string
   */
  public static function generateRandomNonce() : string {
    return hash( 'sha512', mt_rand() . time() );
  }


  /**
   * Execute an HTTP request using PHP's curl library.
   *
   * @param array<int,mixed> $options array of CURLOPT_ parameters for
   *                                  the request
   *
   * @return array{string|false,int} of [response, status].
   *    response will be false if the request fails due to a curl error;
   *    otherwise it will be a string with the result of the request.
   *    status is the integer HTTP status code (e.g., 200, 404, ...) status
   *    is only meaningful if response is not false.
   *
   * @throws MWException for errors in preparing the curl request
   */
  public static function executeCurl( array $options ) : array {
    $curl = curl_init();
    if ( !$curl ) {
      throw new MWException( "curl_init() failed." );
    }
    try {
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
        self::warn( "Curl error {$errno}:  {$error}" );
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
   * Log a debug message.
   *
   * @param string $message the message to log
   *
   * @return void This function returns no value.
   */
  public static function debug( string $message ) : void {
    wfDebugLog( self::LOG_GROUP, $message );
  }


  /**
   * Log a warning message.
   *
   * @param string $message the message to log
   *
   * @return void This function returns no value.
   */
  public static function warn( string $message ) : void {
    // The $callerOffset parameter "2" tells wfLogWarning to identify
    // the function/line that called us as the location of the error.
    wfLogWarning( self::LOG_GROUP . ': ' . $message, 2 );
  }


  /**
   * Strictly assert a runtime invariant, logging an error and backtrace
   * if $condition is false.
   *
   * @param bool $condition the asserted condition
   * @phan-assert-true-condition $condition
   *
   * @return void This function returns no value; if $condition is false,
   *              it does not return, period.
   */
  public static function insist( bool $condition ) : void {
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
   *
   */
  // TODO(maddog) In PHP-8.1, give this the return type "never".
  public static function unreachable() : void {
    // The $callerOffset parameter "2" tells wfLogWarning to identify
    // the function/line that called us as the location of the error.
    wfLogWarning( self::LOG_GROUP . ' REACHED THE UNREACHABLE', 2 );
    exit( 1 );
  }


  /**
   * Convert an object to a JSON-formatted string.
   *
   * @param object $data the object to convert
   *
   * @return string bearing the object as JSON
   *
   * @throws \JsonException if encoding fails.
   */
  public static function encodeObjectAsJson( object $data ) : string {
    $encoded = json_encode( $data,
                            JSON_THROW_ON_ERROR |
                            JSON_UNESCAPED_SLASHES |
                            JSON_UNESCAPED_UNICODE |
                            JSON_PRETTY_PRINT );
    Util::insist( is_string( $encoded ) );
    return $encoded;
  }


  /**
   * Convert a JSON-formatted string to an object.
   *
   * @param string $jsonString the string to convert
   *
   * @return object bearing the data decoded from the JSON string
   *
   * @throws \JsonException if decoding fails.
   */
  public static function decodeJsonAsObject( string $jsonString ) : object {
    $decoded = json_decode( $jsonString, false /*i.e., as object*/,
                            512, JSON_THROW_ON_ERROR );
    Util::insist( is_object( $decoded ) );
    return $decoded;
  }

}
