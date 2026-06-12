"foobér" rlike "^[fq]o{2}\\S.r$" &
"foo" regex "^f..?.$" &
"UPPERCASE" irlike "uppercase" &
"lowercase" irlike "LOWERCASE" &
"1234567" irlike "12345" &
"FoObAR" irlike "^[a-z]+$" &
/* mungeRegexp: slashes in pattern have to be internally escaped,
   unless already escaped by the user */
"/" rlike "^/$" &
"/" rlike "^\\/$" &
/* mungeRegexp: same, when a backslash precedes the slash */
"\\/" rlike "^\\\\/$" &
"\\/" rlike "^\\\\\\/$" &
/* mungeRegexp: when multiple backslashes precede a slash, all are preserved */
"\\\\/" rlike "^\\\\\\\\/$" &
"\\\\/" rlike "^\\\\\\\\\\/$"
