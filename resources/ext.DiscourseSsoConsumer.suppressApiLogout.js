//
// This file is part of DiscourseSsoConsumer.
//
// Copyright 2023 Matt Marjanovic
//
// DiscourseSsoConsumer is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License as published
// by the Free Software Foundation; either version 3 of the License, or any
// later version.
//
// DiscourseSsoConsumer is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
// Public License for more details.
//
// You should have received a copy of the GNU General Public License along
// with DiscourseSsoConsumer.  If not, see <https://www.gnu.org/licenses/>.
//

// Suppress the "logout via an API call" behavior introduced in MW-1.34.
//
// 'mediawiki.page.ready' attaches a 'click' handler to the logout button,
// which posts a request to the logout API, and inhibits the default behavior
// of the link (which would have been to navigate to SpecialLogout).
//
// We revert to the original link behavior, by brutally removing any/all
// 'click' handlers from the logout button.
//
// This module has a registered dependency on 'mediawiki.page.ready', so that
// it executes after that module does.  (Both modules use the "$( callback )"
// mechanism to register callbacks to run once the DOM is ready; these callbacks
// will be executed in the order they are registered.)

// TODO(maddog) Review/revise this for REL1_39.

$( function () {
    $( '#pt-logout a[data-mw="interface"]' ).off( 'click' );
} );
