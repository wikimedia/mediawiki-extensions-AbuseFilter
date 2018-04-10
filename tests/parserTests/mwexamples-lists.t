/* Examples from [[mw:Extension:AbuseFilter/Rules format#Lists]] */

a_list := [ 5, 6, 7, 10];
a_list[0] == 5 &
length(a_list) == 4 &
string(a_list) == "5\n6\n7\n10\n" &
5 in a_list == true &
'5' in a_list == true &
'5\n6' in a_list == true &
1 in a_list == true