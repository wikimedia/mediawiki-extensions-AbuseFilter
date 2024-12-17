-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: db_patches/abstractSchemaChanges/patch-drop-af_user.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TEMPORARY TABLE /*_*/__temp__abuse_filter AS
SELECT
  af_id,
  af_pattern,
  af_actor,
  af_timestamp,
  af_enabled,
  af_comments,
  af_public_comments,
  af_hidden,
  af_hit_count,
  af_throttled,
  af_deleted,
  af_actions,
  af_global,
  af_group
FROM /*_*/abuse_filter;
DROP TABLE /*_*/abuse_filter;


CREATE TABLE /*_*/abuse_filter (
    af_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    af_pattern BLOB NOT NULL,
    af_actor BIGINT UNSIGNED NOT NULL,
    af_timestamp BLOB NOT NULL,
    af_enabled SMALLINT DEFAULT 1 NOT NULL,
    af_comments BLOB DEFAULT NULL,
    af_public_comments BLOB DEFAULT NULL,
    af_hidden SMALLINT DEFAULT 0 NOT NULL,
    af_hit_count BIGINT DEFAULT 0 NOT NULL,
    af_throttled SMALLINT DEFAULT 0 NOT NULL,
    af_deleted SMALLINT DEFAULT 0 NOT NULL,
    af_actions VARCHAR(255) DEFAULT '' NOT NULL,
    af_global SMALLINT DEFAULT 0 NOT NULL,
    af_group BLOB DEFAULT 'default' NOT NULL
  );
INSERT INTO /*_*/abuse_filter (
    af_id, af_pattern, af_actor, af_timestamp,
    af_enabled, af_comments, af_public_comments,
    af_hidden, af_hit_count, af_throttled,
    af_deleted, af_actions, af_global,
    af_group
  )
SELECT
  af_id,
  af_pattern,
  af_actor,
  af_timestamp,
  af_enabled,
  af_comments,
  af_public_comments,
  af_hidden,
  af_hit_count,
  af_throttled,
  af_deleted,
  af_actions,
  af_global,
  af_group
FROM
  /*_*/__temp__abuse_filter;
DROP TABLE /*_*/__temp__abuse_filter;

CREATE INDEX af_actor ON /*_*/abuse_filter (af_actor);

CREATE INDEX af_group_enabled ON /*_*/abuse_filter (af_group, af_enabled, af_id);
