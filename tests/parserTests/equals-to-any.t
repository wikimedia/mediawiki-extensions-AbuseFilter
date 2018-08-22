equals_to_any( "foo", "bar", "foo", "pizza" ) &
equals_to_any( 15, 3, 77, 18, 15 ) &
equals_to_any( "", 3, 77, 18, 15, "duh" ) === false &
equals_to_any( "", 3, 77, 18, 15, "duh", "" )