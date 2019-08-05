/*
These variables are unknown at write-time, so the parser should allow everything
(for instance, it shouldn't throw a "dividebyzero" for the first division).
@ToDo Once T198531 will be resolved, add a line to test that something like "added_lines[0]"
	doesn't throw an exception due to the array length.
@ToDo Find a better way to handle implicit and explicit casts.
*/
amount := float( timestamp / user_age);

5 / length( new_wikitext ) !== 3 ** edit_delta &
float( timestamp / (user_age + 0.000001) ) !== 0.0 &
amount !== 0.0 &
64 / ( amount + 0.1 ) !== 640.0 &
36 / ( length( user_rights ) + 0.00001 ) !== 0 & /* this used to be like 36 / ( 0 + 0.00001 ) */
!("something" in added_lines) &
!(user_groups rlike "foo") &
rcount("x", rescape(page_title) ) !== 0 &
norm(user_name) !== rmspecials("")
