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
-- Patch v0000 to v0001
--

-- (No precondition check; v0000 is implicit since there is no metadata table
-- to query before this patch.)


-- Add the META table.
CREATE TABLE /*_*/discourse_sso_consumer_meta (
  m_key VARCHAR(255) NOT NULL,
  m_value TEXT NOT NULL,
  PRIMARY KEY(m_key)
) /*$wgDBTableOptions*/;

-- Initialize schemaVersion as 1.
INSERT INTO /*_*/discourse_sso_consumer_meta (m_key, m_value)
  VALUES('schemaVersion', '1');
