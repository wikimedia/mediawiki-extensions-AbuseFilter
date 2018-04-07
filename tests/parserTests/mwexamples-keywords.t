/* Examples from [[mw:Extension:AbuseFilter/Rules format#Keywords]] */

("1234" like "12?4") &
("1234" like "12*") &
("foo" in "foobar") &
("o" in ["foo", "bar"]) &
("foo" regex "\w+") &
("a\b" regex "a\\\\b") &
("a\b" regex "a\x5C\x5Cb")