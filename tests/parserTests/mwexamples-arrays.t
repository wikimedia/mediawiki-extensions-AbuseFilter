/* Examples from [[mw:Extension:AbuseFilter/Rules format#Lists]] */

a_array := [ 5, 6, 7, 10];
a_array[0] == 5 &
length(a_array) == 4 &
string(a_array) == "5\n6\n7\n10\n" &
5 in a_array == true &
'5' in a_array == true &
'5\n6' in a_array == true &
1 in a_array == true