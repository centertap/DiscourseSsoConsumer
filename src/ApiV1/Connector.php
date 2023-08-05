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

namespace MediaWiki\Extension\DiscourseSsoConsumer\ApiV1;

use MediaWiki\Extension\DiscourseSsoConsumer\Db;
use MediaWiki\User\UserIdentity;


// TODO(maddog) Potentially implement some hooks:
//              - When a webhook is initially received.
//              - After Discourse UserRecord is updated and committed.

class Connector {

  /**
   * Get the cached Discourse user record for a wiki user.
   *
   * If a cached record exists for the wiki user, this method returns an
   * object with the following fields:
   *
   *  * int discourse_id - Discourse id for the user;
   *  * object user_record - object-decoding of the JSON record received from
   *                         the Discourse 'user' webhook;
   *  * MWTimestamp last_update - timestamp when the user record was received
   *                              from Discourse
   *  * string last_event - the name of the event that delivered the data
   *  * int last_event_id - the id of the event that delivered the data
   *
   * @param UserIdentity $user  the user to query for, identified by a
   *                            non-zero (non-anonymous) id
   *
   * @return object|null If a user-record for the wiki-id exists, returns
   *  an object bearing members discourse_id, user_record, last_update,
   *  last_event, last_event_id; otherwise returns null.
   */
  public static function getUserRecord( UserIdentity $user ) : ?object {
    return Db::fetchUserRecordByWikiId( $user->getId() );
  }
  // TODO(maddog) In PHP-8.1, consider creating a class type for the return
  //              value, with constructor property promotion.
}
