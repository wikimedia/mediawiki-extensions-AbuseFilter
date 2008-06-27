<?

require( '/home/andrew/mediawiki/maintenance/commandLine.inc' );

while ( ( $line = readconsole( '> ' ) ) !== false ) {
	$line = trim($line);

	print "Testing $line...\n";
	$result = AbuseFilter::checkTitleText( $line );
	print "-Result: ";
	var_dump( $result );
}