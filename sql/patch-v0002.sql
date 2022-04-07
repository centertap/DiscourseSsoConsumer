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

--
-- Patch v0001 to v0002
--

-- Ensure we are at v0001 (if not, fail with NOT NULL constraint violation).
INSERT INTO /*_*/discourse_sso_consumer_meta (m_key, m_value)
  SELECT 'CheckSchemaVersionPrecondition',
         (SELECT m_value FROM /*_*/discourse_sso_consumer_meta
            WHERE m_key = 'schemaVersion' AND m_value = '1');
DELETE FROM /*_*/discourse_sso_consumer_meta
  WHERE m_key = 'CheckSchemaVersionPrecondition';


-- Rename original lone table to be LINK table.
ALTER TABLE /*_*/discourse_sso_consumer
  RENAME TO /*_*/discourse_sso_consumer_link;


-- Bump schemaVersion to 2.
UPDATE /*_*/discourse_sso_consumer_meta
  SET m_value = '2'
  WHERE m_key = 'schemaVersion';

