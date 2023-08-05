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
use WebRequest;
use WebResponse;


class Sso {

  public const AUTH_COOKIE = 'DiscourseSsoConsumerAuth';

  // TODO(maddog) In PHP-8.1, turn these into a backed enumeration.
  public const AUTH_COOKIE_DESIRED = 'yes';
  public const AUTH_COOKIE_NO_MORE = 'no';
  public const AUTH_COOKIE_PRESENT = 'present';
  public const AUTH_COOKIE_PROBING_QUIET = 'probing_quiet';
  public const AUTH_COOKIE_PROBING_NOISY = 'probing_noisy';


  /**
   * Set our auth intent marker cookie, to indicate whether authentication
   * via Discourse SSO has been successful and whether or not we should try
   * it again in the future.
   *
   * MW's defaults for cookie parameters should be sufficient.
   *
   * @param WebResponse $response the response in which to set the cookie
   * @param string $value the value to set, which should be one of
   *               AUTH_COOKIE_NO_MORE, AUTH_COOKIE_DESIRED,
   *               AUTH_COOKIE_PROBING_QUIET, AUTH_COOKIE_PROBING_NOISY,
   *               AUTH_COOKIE_PRESENT
   *
   * @return void This function returns no value.
   */
  public static function setAuthCookie( WebResponse $response,
                                        string $value ): void {
    $response->setCookie( self::AUTH_COOKIE, $value );
  }


  /**
   * Get the auth intent marker cookie.
   *
   * @param WebRequest $request the request from which to get the cookie
   *
   * @return ?string the string value of the cookie, or null if no such cookie.
   */
  public static function getAuthCookie( WebRequest $request ) : ?string {
    return $request->getCookie( self::AUTH_COOKIE,
                                null/*== use default prefix*/ );
  }


  /**
   * Construct a signed SSO request payload and redirect the user's browser
   * to the Discourse SSO provider endpoint with the request.
   *
   * @param string $nonce random nonce for this SSO interaction
   * @param string $returnAddress URL to which Discourse should return the
   *                              SSO results (via a browser redirect)
   * @param bool $isProbe true if Discourse should only probe current state
   * @param bool $isLogout true if Discourse should logout the user
   *
   * This method does not return.
   */
  // TODO(maddog) In PHP-8.1, give this the return type "never".
  public static function redirectToSsoEndpointAndExit( string $nonce,
                                                       string $returnAddress,
                                                       bool $isProbe,
                                                       bool $isLogout ) : void {
    Util::debug( "Nonce: '{$nonce}'" );
    Util::debug( "Return address URL: '{$returnAddress}'" );
    Util::debug( "Is probe? " . var_export( $isProbe, true ) );
    Util::debug( "Is logout? " . var_export( $isLogout, true ) );
    $payload = base64_encode(
      http_build_query( [ 'nonce' => $nonce,
                          'return_sso_url' => $returnAddress,
                          'prompt' => ( $isProbe === true ? "none" : null ),
                          'logout' => ( $isLogout === true ? "true" : null ),
      ] ) );

    // Sign the payload with the shared-secret, and construct the parameters
    // for the SSO request URL.
    $sharedSecret = Config::config()['Sso']['SharedSecret'];
    $ssoParameters = [ 'sso' => $payload,
                       'sig' => hash_hmac( 'sha256',
                                           $payload, $sharedSecret ), ];

    // Redirect user's browser to Discourse.
    $ssoProviderUrl =
        Config::config()['DiscourseUrl'] .
        Config::config()['Sso']['ProviderEndpoint'];
    Util::debug( "Redirecting to Discourse at '{$ssoProviderUrl}'." );
    Util::redirectToUrlAndExit( $ssoProviderUrl, $ssoParameters );
  }


  /**
   * Validate the response from the Discourse SSO Provider endpoint and
   * unpack the SSO credentials.
   *
   * @param WebRequest $request  a WebRequest bearing the DiscourseSSO reply
   * @param string $originalNonce the nonce used to initiate this
   *                              authentication flow
   *
   * @return ?array bearing the SSO credentials, or null if no credentials
   * @throws MWException if validation or unpacking fails
   */
  public static function validateAndUnpackCredentials(
      WebRequest $request, string $originalNonce ) : ?array {
    $sso = $request->getVal( 'sso' );
    $sig = $request->getVal( 'sig' );
    if ( !$sso || !$sig ) {
      throw new MWException(
        'Missing sso or sig parameters in Discourse SSO response.' );
    }

    $sharedSecret = Config::config()['Sso']['SharedSecret'];
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

    // If the authentication failed "successfully" (i.e., interaction complete,
    // but Discourse reports no authentication), then there are no credentials
    // to return.
    if ( $unpackedResponse['failed'] ) {
      return null;
    }

    // TODO(maddog) Consider moving this higher up in the call chain.
    // We should not receive a non-positive Discourse ID; if we do, something
    // is wrong (in Discourse, or in our own parsing).
    if ( $unpackedResponse['credentials']['discourse_id'] <= 0 ) {
      throw new MWException(
        "Invalid discourse_id ({$unpackedResponse['credentials']['discourse_id']}) received." );
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
   * @throws MWException if unpacking fails
   */
  public static function unpackSsoResponse( string $packedResponse ) : array {
    $decoded = base64_decode( $packedResponse, true/*strict*/ );
    Util::debug( "Received response:  '{$decoded}'" );
    if ( $decoded === false ) {
      throw new MWException( "Failed to decode payload '{$packedResponse}'" );
    }

    $parsed = [];
    parse_str( $decoded, $parsed );

    $response = [
      'nonce' => $parsed['nonce'],
      'return_sso_url' => $parsed['return_sso_url'],
    ];
    if ( ( $parsed['failed'] ?? 'false' ) === 'true' ) {
      $response['failed'] = true;
      $response['credentials'] = null;
    } else {
      $response['failed'] = false;
      $response['credentials'] = [
        'discourse_id' => (int)$parsed['external_id'],
        'username' => $parsed['username'],
        // NB: 'name' in the response can be null if the Discourse user
        //     never set their name.  We'll treat that as empty string.
        'name' => $parsed['name'] ?? '',
        'email' => $parsed['email'],
        'groups' => explode( ',', $parsed['groups'] ),
        'is_admin' => ( $parsed['admin'] === 'true' ) ? true : false,
        'is_moderator' => ( $parsed['moderator'] === 'true' ) ? true : false,
      ];
    }
    return $response;
  }

}
