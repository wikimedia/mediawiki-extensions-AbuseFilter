-- Add abuse_filter_log idex for afl_wiki.

ALTER TABLE /*_*/abuse_filter_log ADD KEY wiki_timestamp (afl_wiki, afl_timestamp);
