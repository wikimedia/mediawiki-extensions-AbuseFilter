/* More complete tests for get_matches are in AbuseFilterParserTest.php */
a := get_matches('I am a (dog|cat)', 'What did you say?');
get_matches('The (truth|pineapple) is (?:rarely)? pure and (nee*v(ah|er) sh?imple)', 'The truth is rarely pure and never simple, Wilde said') == ['The truth is rarely pure and never simple', 'truth', 'never simple', 'er'] &
a === [false, false]