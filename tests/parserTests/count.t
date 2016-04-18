count("a,b,c,d") = 4 &
count(",", "a,b,c,d") = 3 &
count("", "abcd") = 0 &
count("a", "abab") = 2 &
count("ab", "abab") = 2 &
/* This probably shouldn't count overlapping occurrences... */
count("aa", "aaaaa") = 4
