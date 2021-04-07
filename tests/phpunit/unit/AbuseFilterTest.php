<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilter;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterGeneric
 */
class AbuseFilterTest extends MediaWikiUnitTestCase {
	/**
	 * @param RevisionRecord $revRec
	 * @param bool $privileged
	 * @param bool $expected
	 * @dataProvider provideUserCanViewRev
	 * @covers AbuseFilter::userCanViewRev
	 */
	public function testUserCanViewRev( RevisionRecord $revRec, bool $privileged, bool $expected ) {
		$authority = new SimpleAuthority(
			$this->createMock( UserIdentity::class ),
			$privileged ? [ 'viewsuppressed' ] : []
		);
		$this->assertSame( $expected, AbuseFilter::userCanViewRev( $revRec, $authority ) );
	}

	/**
	 * @return Generator|array
	 */
	public function provideUserCanViewRev() {
		$page = new PageIdentityValue( 1, NS_MAIN, 'Foo', PageIdentityValue::LOCAL );

		$visible = new MutableRevisionRecord( $page );
		yield 'Visible, not privileged' => [ $visible, false, true ];
		yield 'Visible, privileged' => [ $visible, true, true ];

		$userSup = new MutableRevisionRecord( $page );
		$userSup->setVisibility( RevisionRecord::SUPPRESSED_USER );
		yield 'User suppressed, not privileged' => [ $userSup, false, false ];
		yield 'User suppressed, privileged' => [ $userSup, true, true ];

		$allSupp = new MutableRevisionRecord( $page );
		$allSupp->setVisibility( RevisionRecord::SUPPRESSED_ALL );
		yield 'All suppressed, not privileged' => [ $allSupp, false, false ];
		yield 'All suppressed, privileged' => [ $allSupp, true, true ];
	}
}
