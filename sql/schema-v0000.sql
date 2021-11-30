-- This file is part of DiscourseSsoConsumer.
--
-- Copyright 2020,2021 Matt Marjanovic
--
-- DiscourseSsoConsumer is free software; you can redistribute it and/or
-- modify it under the terms of the GNU General Public License as published
-- by the Free Software Foundation; either version 3 of the License, or any
-- later version.
--
-- DiscourseSsoConsumer is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
-- Public License for more details.
--
-- You should have received a copy of the GNU General Public License along
-- with DiscourseSsoConsumer.  If not, see <https://www.gnu.org/licenses/>.

-- The purpose of this table is to link user-ids from the Discourse SSO
-- service (external_id) to user-ids in the MediaWiki instance (local_id).
CREATE TABLE /*_*/discourse_sso_consumer (
  external_id INTEGER NOT NULL,
  local_id INTEGER NOT NULL,
  PRIMARY KEY(external_id)
) /*$wgDBTableOptions*/;
