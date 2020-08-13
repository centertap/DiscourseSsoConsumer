-- This file is part of Discourse_SSO_Consumer.
--
-- Copyright 2020 Matt Marjanovic
--
-- This program is free software; you can redistribute it and/or modify it
-- under the terms of the GNU General Public License as published by the Free
-- Software Foundation; either version 2 of the License, or any later version.
--
-- This program is distributed in the hope that it will be useful, but WITHOUT
-- ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
-- FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
-- more details.
--
-- You should have received a copy of the GNU General Public License along
-- with this program (see the file `COPYING`); if not, write to the
--
--   Free Software Foundation, Inc.,
--   59 Temple Place, Suite 330,
--   Boston, MA 02111-1307
--   USA

-- The purpose of this table is to link user-ids from the Discourse SSO
-- service (external_id) to user-ids in the MediaWiki instance (local_id).
CREATE TABLE /*_*/discourse_sso_consumer (
  external_id INTEGER NOT NULL PRIMARY KEY,
  local_id INTEGER NOT NULL
);
