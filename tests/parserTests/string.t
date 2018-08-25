"a\tb" === "a	b" &
"a\qb" === "a\qb" &
"a\"b" === 'a"b' &
"a\rb" !== "a\r\nb" &
"\x66\x6f\x6f" === "foo" &
"some\xstring" === "somexstring"
