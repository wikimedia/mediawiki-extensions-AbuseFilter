-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: db_patches/abstractSchemaChanges/patch-drop-afl_patrolled_by.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE abuse_filter_log
  DROP afl_patrolled_by;
