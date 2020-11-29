<?php

namespace MediaWiki\Extension\AbuseFilter\View;

use HTMLForm;
use IContextSource;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\EditBoxBuilderFactory;
use MediaWiki\Linker\LinkRenderer;
use OOUI;
use Xml;

class AbuseFilterViewTools extends AbuseFilterView {

	/**
	 * @var EditBoxBuilderFactory
	 */
	private $boxBuilderFactory;

	/**
	 * @param AbuseFilterPermissionManager $afPermManager
	 * @param EditBoxBuilderFactory $boxBuilderFactory
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string $basePageName
	 * @param array $params
	 */
	public function __construct(
		AbuseFilterPermissionManager $afPermManager,
		EditBoxBuilderFactory $boxBuilderFactory,
		IContextSource $context,
		LinkRenderer $linkRenderer,
		string $basePageName,
		array $params
	) {
		parent::__construct( $afPermManager, $context, $linkRenderer, $basePageName, $params );
		$this->boxBuilderFactory = $boxBuilderFactory;
	}

	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		$out->enableOOUI();
		$out->addHelpLink( 'Extension:AbuseFilter/Rules format' );
		$request = $this->getRequest();

		if ( !$this->afPermManager->canViewPrivateFilters( $this->getUser() ) ) {
			$out->addWikiMsg( 'abusefilter-mustviewprivateoredit' );
			return;
		}

		// Header
		$out->addWikiMsg( 'abusefilter-tools-text' );

		$boxBuilder = $this->boxBuilderFactory->newEditBoxBuilder( $this, $this->getUser(), $out );

		// Expression evaluator
		$eval = '';
		$eval .= $boxBuilder->buildEditBox(
			$request->getText( 'wpFilterRules' ),
			true,
			false,
			false
		);

		$eval .=
			Xml::tags( 'p', null,
				new OOUI\ButtonInputWidget( [
					'label' => $this->msg( 'abusefilter-tools-submitexpr' )->text(),
					'id' => 'mw-abusefilter-submitexpr',
					'flags' => [ 'primary', 'progressive' ]
				] )
			);
		$eval .= Xml::element( 'pre', [ 'id' => 'mw-abusefilter-expr-result' ], ' ' );

		$eval = Xml::fieldset( $this->msg( 'abusefilter-tools-expr' )->text(), $eval );
		$out->addHTML( $eval );

		$out->addModules( 'ext.abuseFilter.tools' );

		if ( $this->afPermManager->canEdit( $this->getUser() ) ) {
			// Hacky little box to re-enable autoconfirmed if it got disabled
			$formDescriptor = [
				'RestoreAutoconfirmed' => [
					'label-message' => 'abusefilter-tools-reautoconfirm-user',
					'type' => 'user',
					'name' => 'wpReAutoconfirmUser',
					'id' => 'reautoconfirm-user',
					'infusable' => true
				],
			];
			$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
			$htmlForm->setWrapperLegendMsg( 'abusefilter-tools-reautoconfirm' )
				->setSubmitTextMsg( 'abusefilter-tools-reautoconfirm-submit' )
				->setSubmitName( 'wpReautoconfirmSubmit' )
				->setSubmitId( 'mw-abusefilter-reautoconfirmsubmit' )
				->prepareForm()
				->displayForm( false );
		}
	}
}
