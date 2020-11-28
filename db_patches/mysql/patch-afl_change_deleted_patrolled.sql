UPDATE /*_*/abuse_filter_log SET afl_deleted = 0 WHERE afl_deleted IS NULL;
UPDATE /*_*/abuse_filter_log SET afl_patrolled_by = 0 WHERE afl_patrolled_by IS NULL;
ALTER TABLE /*_*/abuse_filter_log MODIFY afl_deleted tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE /*_*/abuse_filter_log MODIFY afl_patrolled_by int unsigned NOT NULL DEFAULT 0;