-- Drop wiki_timestamp index; it was previously created on MySQL only, it's now named afl_wiki_timestamp for uniformity

DROP INDEX /*i*/wiki_timestamp ON /*_*/abuse_filter_log;
