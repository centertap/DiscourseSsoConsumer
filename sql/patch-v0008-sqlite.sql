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

--
-- Patch v0007 to v0008
--

-- Ensure we are at v0007 (if not, fail with NOT NULL constraint violation).
INSERT INTO /*_*/discourse_sso_consumer_meta (m_key, m_value)
  SELECT 'CheckSchemaVersionPrecondition',
         (SELECT m_value FROM /*_*/discourse_sso_consumer_meta
            WHERE m_key = 'schemaVersion' AND m_value = '7');
DELETE FROM /*_*/discourse_sso_consumer_meta
  WHERE m_key = 'CheckSchemaVersionPrecondition';


-- Add _discourse_user table
CREATE TABLE /*_*/discourse_sso_consumer_discourse_user (
  discourse_id INTEGER NOT NULL,
  user_json BLOB NOT NULL,
  last_update BLOB NOT NULL,
  last_event TEXT NOT NULL,
  last_event_id INTEGER NOT NULL,
  PRIMARY KEY(discourse_id)
) /*$wgDBTableOptions*/;


-- Bump schemaVersion to 8.
UPDATE /*_*/discourse_sso_consumer_meta
  SET m_value = '8'
  WHERE m_key = 'schemaVersion';

