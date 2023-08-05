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
-- Patch v0006 to v0007
--

-- Ensure we are at v0006 (if not, fail with NOT NULL constraint violation).
INSERT INTO /*_*/discourse_sso_consumer_meta (m_key, m_value)
  SELECT 'CheckSchemaVersionPrecondition',
         (SELECT m_value FROM /*_*/discourse_sso_consumer_meta
            WHERE m_key = 'schemaVersion' AND m_value = '6');
DELETE FROM /*_*/discourse_sso_consumer_meta
  WHERE m_key = 'CheckSchemaVersionPrecondition';


-- Rename discourse_sso_consumer_link_local_id index to ..._wiki_id --- part 2:
ALTER TABLE discourse_sso_consumer_link
RENAME INDEX discourse_sso_consumer_link_local_id
TO discourse_sso_consumer_link_wiki_id;


-- Bump schemaVersion to 7.
UPDATE /*_*/discourse_sso_consumer_meta
  SET m_value = '7'
  WHERE m_key = 'schemaVersion';

