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


class DiscourseApi {

  /**
   * Ask Discourse to logout a user, via a request to the Discourse server's
   * log_out API endpoint.  This is a global logout, which will invalidate
   * all of the user's sessions on all devices.
   *
   * @param int $discourseId id of the Discourse user
   *
   * @return void
   * @throws MWException if the request fails
   */
  public static function logoutUser( int $discourseId ): void {
    $apiConfig = Config::config()['DiscourseApi'];
    $fullLogoutUrl = Config::config()['DiscourseUrl'] .
        str_replace( '{id}', (string) $discourseId,
                     $apiConfig['LogoutEndpoint'] );

    $options = [
      // POST query
      CURLOPT_POST             => true,
      // Return response in return value of curl_exec()
      CURLOPT_RETURNTRANSFER  => true,
      CURLOPT_URL => $fullLogoutUrl,
      CURLOPT_HTTPHEADER      => [
        'Accept: application/json',
        "Api-Key: {$apiConfig['Key']}",
        "Api-Username: {$apiConfig['Username']}",
      ],
    ];

    Util::debug(
      "Sending Api logout request id {$discourseId} to {$fullLogoutUrl}..." );

    [ $response, $httpStatus ] = Util::executeCurl( $options );

    if ( ( $response === false ) || ( $httpStatus !== 200 ) ) {
      Util::debug( "Request failed.  Status {$httpStatus}.  Response " .
                   var_export( $response, true ) );
      throw new MWException( 'DiscourseApi logout request failed.' );
    }

    $response = json_decode( $response, /*as array:*/true );
    if ( ( $response['success'] ?? '' ) !== 'OK' ) {
      Util::debug(
          'Request failed.  Response ' . var_export( $response, true ) );
      throw new MWException( 'Discourse Logout request failed.' );
    }

    Util::debug( 'DiscourseApi logout succeeded.' );
  }

}
