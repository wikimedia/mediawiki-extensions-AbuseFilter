-- Store the ID of successful actions in the abuse_filter_log table.
ALTER TABLE /*_*/abuse_filter_log
	ADD COLUMN afl_rev_id int unsigned;
CREATE INDEX /*i*/afl_rev_id ON abuse_filter_log (afl_rev_id);

ALTER TABLE /*_*/abuse_filter_log
	ADD COLUMN afl_log_id int unsigned;
CREATE INDEX /*i*/afl_log_id ON abuse_filter_log (afl_log_id);