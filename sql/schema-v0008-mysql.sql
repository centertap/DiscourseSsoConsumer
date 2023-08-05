-- This file is part of DiscourseSsoConsumer.
--
-- Copyright 2023 Matt Marjanovic
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


-- Link table, to link user-ids from the Discourse SSO service (discourse_id)
-- to user-ids in the MediaWiki instance (wiki_id).
CREATE TABLE /*_*/discourse_sso_consumer_link (
  discourse_id INTEGER NOT NULL,
  wiki_id INTEGER NOT NULL,
  PRIMARY KEY(discourse_id)
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX discourse_sso_consumer_link_wiki_id
  ON /*_*/discourse_sso_consumer_link (wiki_id);


-- User table, to cache user records from Discourse, received via a webhook.
CREATE TABLE /*_*/discourse_sso_consumer_discourse_user (
  discourse_id INTEGER NOT NULL,
  user_json MEDIUMBLOB NOT NULL,
  last_update BINARY(14) NOT NULL,
  last_event TEXT NOT NULL,
  last_event_id INTEGER NOT NULL,
  PRIMARY KEY(discourse_id)
) /*$wgDBTableOptions*/;



INSERT INTO /*_*/discourse_sso_consumer_meta (m_key, m_value)
  VALUES('schemaVersion', '7');
