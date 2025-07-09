<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/CheckUser',
		'../../extensions/ConfirmEdit',
		'../../extensions/Echo',
		'../../extensions/UserMerge',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/CheckUser',
		'../../extensions/ConfirmEdit',
		'../../extensions/Echo',
		'../../extensions/UserMerge',
	]
);

$cfg['exception_classes_with_optional_throws_phpdoc'] = [
	...$cfg['exception_classes_with_optional_throws_phpdoc'],
	\MediaWiki\Extension\AbuseFilter\Parser\Exception\ExceptionBase::class,
];

// Temporary block until https://gerrit.wikimedia.org/r/c/mediawiki/tools/phan/+/1162102 is released
if ( in_array( 'PhanPossiblyInfiniteRecursionSameParams', $cfg['suppress_issue_types'], true ) ) {
	$cfg['suppress_issue_types'] = array_diff(
		$cfg['suppress_issue_types'],
		[ 'PhanPossiblyInfiniteRecursionSameParams' ]
	);
} else {
	throw new \Error( "Drop this block when updating phan." );
}

return $cfg;
