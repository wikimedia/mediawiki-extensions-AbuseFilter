<?php

use MediaWiki\Extension\AbuseFilter\Consequences\Consequence\HookAborterConsequence;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @method MockObject createMock($class)
 * @method assertSame($x,$y,$msg='')
 * @method assertStringMatchesFormat($fmt,$str,$msg='')
 */
trait ConsequenceGetMessageTestTrait {
	/**
	 * @param UserIdentity|string|null $user Test name when used as data provider, a UserIdentity can be passed when
	 * called explicitly
	 * @return Generator
	 */
	public function provideGetMessageParameters( $user = null ) : Generator {
		$user = $user instanceof UserIdentity
			? $user
			: new UserIdentityValue( 1, 'getMessage test user', 2 );
		$localFilter = MutableFilter::newDefault();
		$localFilter->setID( 1 );
		$localFilter->setName( 'Local filter' );
		$localParams = new Parameters(
			$localFilter,
			false,
			$user,
			$this->createMock( LinkTarget::class ),
			'edit'
		);
		yield 'local filter' => [ $localParams ];

		$globalFilter = MutableFilter::newDefault();
		$globalFilter->setID( 3 );
		$globalFilter->setName( 'Global filter' );
		$globalParams = new Parameters(
			$globalFilter,
			true,
			$user,
			$this->createMock( LinkTarget::class ),
			'edit'
		);
		yield 'global filter' => [ $globalParams ];
	}

	/**
	 * @param HookAborterConsequence $consequence
	 * @param Parameters $params
	 * @param string $msg
	 */
	protected function doTestGetMessage(
		HookAborterConsequence $consequence,
		Parameters $params,
		string $msg
	) : void {
		$actualMsg = $consequence->getMessage();
		$this->assertSame( $msg, $actualMsg[0], 'message' );
		$this->assertSame( $params->getFilter()->getName(), $actualMsg[1], 'name' );
		$format = $params->getIsGlobalFilter() ? 'global-%d' : '%d';
		$this->assertStringMatchesFormat( $format, $actualMsg[2], 'global name' );
	}
}
