-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: db_patches/abstractSchemaChanges/patch-add-afh_actor.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TEMPORARY TABLE /*_*/__temp__abuse_filter_history AS
SELECT
  afh_id,
  afh_filter,
  afh_user,
  afh_user_text,
  afh_timestamp,
  afh_pattern,
  afh_comments,
  afh_flags,
  afh_public_comments,
  afh_actions,
  afh_deleted,
  afh_changed_fields,
  afh_group
FROM /*_*/abuse_filter_history;
DROP TABLE /*_*/abuse_filter_history;


CREATE TABLE /*_*/abuse_filter_history (
    afh_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    afh_filter BIGINT UNSIGNED NOT NULL,
    afh_user BIGINT UNSIGNED DEFAULT 0 NOT NULL,
    afh_user_text BLOB DEFAULT '' NOT NULL,
    afh_timestamp BLOB NOT NULL,
    afh_pattern BLOB NOT NULL,
    afh_comments BLOB NOT NULL,
    afh_flags BLOB NOT NULL,
    afh_public_comments BLOB DEFAULT NULL,
    afh_actions BLOB DEFAULT NULL,
    afh_deleted SMALLINT DEFAULT 0 NOT NULL,
    afh_changed_fields VARCHAR(255) DEFAULT '' NOT NULL,
    afh_group BLOB DEFAULT NULL,
    afh_actor BIGINT UNSIGNED DEFAULT 0 NOT NULL
  );
INSERT INTO /*_*/abuse_filter_history (
    afh_id, afh_filter, afh_user, afh_user_text,
    afh_timestamp, afh_pattern, afh_comments,
    afh_flags, afh_public_comments,
    afh_actions, afh_deleted, afh_changed_fields,
    afh_group
  )
SELECT
  afh_id,
  afh_filter,
  afh_user,
  afh_user_text,
  afh_timestamp,
  afh_pattern,
  afh_comments,
  afh_flags,
  afh_public_comments,
  afh_actions,
  afh_deleted,
  afh_changed_fields,
  afh_group
FROM
  /*_*/__temp__abuse_filter_history;
DROP TABLE /*_*/__temp__abuse_filter_history;

CREATE INDEX afh_filter ON /*_*/abuse_filter_history (afh_filter);

CREATE INDEX afh_user ON /*_*/abuse_filter_history (afh_user);

CREATE INDEX afh_user_text ON /*_*/abuse_filter_history (afh_user_text);

CREATE INDEX afh_timestamp ON /*_*/abuse_filter_history (afh_timestamp);

CREATE INDEX afh_actor ON /*_*/abuse_filter_history (afh_actor);
