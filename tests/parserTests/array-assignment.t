test_array := [ [1, 2], [3, 4] ];

test_array[1] := 42;
test_array[] := 17;

test_array[0][0] == 1 & test_array[0][1] == 2 & test_array[1] == 42 & test_array[2] == 17
