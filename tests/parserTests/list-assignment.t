test_list := [ [1, 2], [3, 4] ];

test_list[1] := 42;
test_list[] := 17;

test_list[0][0] == 1 & test_list[0][1] == 2 & test_list[1] == 42 & test_list[2] == 17
