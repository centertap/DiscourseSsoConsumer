-- This file is part of DiscourseSsoConsumer.
--
-- Copyright 2021 Matt Marjanovic
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


-- Metadata table, to explicitly track the status of the schema/DDL itself.
CREATE TABLE /*_*/discourse_sso_consumer_meta (
  m_key VARCHAR(255) NOT NULL,
  m_value TEXT NOT NULL,
  PRIMARY KEY(m_key)
) /*$wgDBTableOptions*/;


-- Link table, to link user-ids from the Discourse SSO service (external_id)
-- to user-ids in the MediaWiki instance (local_id).
CREATE TABLE /*_*/discourse_sso_consumer_link (
  external_id INTEGER NOT NULL,
  local_id INTEGER NOT NULL,
  PRIMARY KEY(external_id)
) /*$wgDBTableOptions*/;


INSERT INTO /*_*/discourse_sso_consumer_meta (m_key, m_value)
  VALUES('schemaVersion', '2');
