-- Add af_group field to abuse_filter table
ALTER TABLE /*_*/abuse_filter add column af_group varchar(64) binary NOT NULL DEFAULT 'default';
ALTER TABLE /*_*/abuse_filter_history add column afh_group varchar(64) binary NULL;

CREATE INDEX /*i*/af_group ON /*_*/abuse_filter (af_group,af_enabled,af_id);