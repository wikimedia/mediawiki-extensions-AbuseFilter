<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterParser;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterTokenizer;
use MessageLocalizer;
use OOUI\ButtonWidget;
use OOUI\DropdownInputWidget;
use OOUI\FieldLayout;
use OOUI\FieldsetLayout;
use OOUI\HorizontalLayout;
use OOUI\Widget;
use OutputPage;
use User;
use Xml;

/**
 * Class responsible for building filter edit boxes
 * @todo Consider splitting to different classes for each editor (plain, Ace, etc.)
 */
class EditBoxBuilder {

	/** @var AbuseFilterPermissionManager */
	private $afPermManager;

	/** @var KeywordsManager */
	private $keywordsManager;

	/** @var bool */
	private $isCodeEditorLoaded;

	/** @var MessageLocalizer */
	private $localizer;

	/** @var User */
	private $user;

	/** @var OutputPage */
	private $output;

	/**
	 * @param AbuseFilterPermissionManager $afPermManager
	 * @param KeywordsManager $keywordsManager
	 * @param bool $isCodeEditorLoaded
	 * @param MessageLocalizer $messageLocalizer
	 * @param User $user
	 * @param OutputPage $output
	 */
	public function __construct(
		AbuseFilterPermissionManager $afPermManager,
		KeywordsManager $keywordsManager,
		bool $isCodeEditorLoaded,
		MessageLocalizer $messageLocalizer,
		User $user,
		OutputPage $output
	) {
		$this->afPermManager = $afPermManager;
		$this->keywordsManager = $keywordsManager;
		$this->isCodeEditorLoaded = $isCodeEditorLoaded;
		$this->localizer = $messageLocalizer;
		$this->user = $user;
		$this->output = $output;
	}

	/**
	 * @param string $rules
	 * @param bool $addResultDiv
	 * @param bool $externalForm
	 * @param bool $needsModifyRights
	 * @param-taint $rules none
	 * @return string
	 */
	public function buildEditBox(
		string $rules,
		bool $addResultDiv = true,
		bool $externalForm = false,
		bool $needsModifyRights = true
	) : string {
		$this->output->enableOOUI();
		$this->output->addModules( 'ext.abuseFilter.edit' );

		// Rules are in English
		$editorAttribs = [ 'dir' => 'ltr' ];

		$noTestAttrib = [];
		$isUserAllowed = $needsModifyRights ?
			$this->afPermManager->canEdit( $this->user ) :
			$this->afPermManager->canViewPrivateFilters( $this->user );
		if ( !$isUserAllowed ) {
			$noTestAttrib['disabled'] = 'disabled';
			$addResultDiv = false;
		}

		$rules = rtrim( $rules ) . "\n";
		$switchEditor = null;

		$rulesContainer = '';
		if ( $this->isCodeEditorLoaded ) {
			$aceAttribs = [
				'name' => 'wpAceFilterEditor',
				'id' => 'wpAceFilterEditor',
				'class' => 'mw-abusefilter-editor'
			];
			$attribs = array_merge( $editorAttribs, $aceAttribs );

			$switchEditor = new ButtonWidget(
				[
					'label' => $this->localizer->msg( 'abusefilter-edit-switch-editor' )->text(),
					'id' => 'mw-abusefilter-switcheditor'
				] + $noTestAttrib
			);

			$rulesContainer .= Xml::element( 'div', $attribs, $rules );

			// Add Ace configuration variable
			$editorConfig = $this->getAceConfig( $isUserAllowed );
			$this->output->addJsConfigVars( 'aceConfig', $editorConfig );
		}

		// Build a dummy textarea to be used: for submitting form if CodeEditor isn't installed,
		// and in case JS is disabled (with or without CodeEditor)
		if ( !$isUserAllowed ) {
			$editorAttribs['readonly'] = 'readonly';
		}
		if ( $externalForm ) {
			$editorAttribs['form'] = 'wpFilterForm';
		}
		$rulesContainer .= Xml::textarea( 'wpFilterRules', $rules, 40, 15, $editorAttribs );

		if ( $isUserAllowed ) {
			// Generate builder drop-down
			$rawDropDown = $this->keywordsManager->getBuilderValues();

			// The array needs to be rearranged to be understood by OOUI. It comes with the format
			// [ group-msg-key => [ text-to-add => text-msg-key ] ] and we need it as
			// [ group-msg => [ text-msg => text-to-add ] ]
			// Also, the 'other' element must be the first one.
			$dropDownOptions = [ $this->localizer->msg( 'abusefilter-edit-builder-select' )->text() => 'other' ];
			foreach ( $rawDropDown as $group => $values ) {
				// Give grep a chance to find the usages:
				// abusefilter-edit-builder-group-op-arithmetic, abusefilter-edit-builder-group-op-comparison,
				// abusefilter-edit-builder-group-op-bool, abusefilter-edit-builder-group-misc,
				// abusefilter-edit-builder-group-funcs, abusefilter-edit-builder-group-vars
				$localisedGroup = $this->localizer->msg( "abusefilter-edit-builder-group-$group" )->text();
				$dropDownOptions[ $localisedGroup ] = array_flip( $values );
				$newKeys = array_map(
					function ( $key ) use ( $group ) {
						return $this->localizer->msg( "abusefilter-edit-builder-$group-$key" )->text();
					},
					array_keys( $dropDownOptions[ $localisedGroup ] )
				);
				$dropDownOptions[ $localisedGroup ] = array_combine(
					$newKeys,
					$dropDownOptions[ $localisedGroup ]
				);
			}

			$dropDownList = Xml::listDropDownOptionsOoui( $dropDownOptions );
			$dropDown = new DropdownInputWidget( [
				'name' => 'wpFilterBuilder',
				'inputId' => 'wpFilterBuilder',
				'options' => $dropDownList
			] );

			$formElements = [ new FieldLayout( $dropDown ) ];

			// Button for syntax check
			$syntaxCheck = new ButtonWidget(
				[
					'label' => $this->localizer->msg( 'abusefilter-edit-check' )->text(),
					'id' => 'mw-abusefilter-syntaxcheck'
				] + $noTestAttrib
			);

			// Button for switching editor (if Ace is used)
			if ( $switchEditor !== null ) {
				$formElements[] = new FieldLayout(
					new Widget( [
						'content' => new HorizontalLayout( [
							'items' => [ $switchEditor, $syntaxCheck ]
						] )
					] )
				);
			} else {
				$formElements[] = new FieldLayout( $syntaxCheck );
			}

			$fieldSet = new FieldsetLayout( [
				'items' => $formElements,
				'classes' => [ 'mw-abusefilter-edit-buttons', 'mw-abusefilter-javascript-tools' ]
			] );

			$rulesContainer .= $fieldSet;
		}

		if ( $addResultDiv ) {
			$rulesContainer .= Xml::element(
				'div',
				[ 'id' => 'mw-abusefilter-syntaxresult', 'style' => 'display: none;' ],
				'&#160;'
			);
		}

		return $rulesContainer;
	}

	/**
	 * Extract values for syntax highlight
	 *
	 * @param bool $canEdit
	 * @return array
	 */
	private function getAceConfig( bool $canEdit ): array {
		$values = $this->keywordsManager->getBuilderValues();
		$deprecatedVars = $this->keywordsManager->getDeprecatedVariables();

		$builderVariables = implode( '|', array_keys( $values['vars'] ) );
		$builderFunctions = implode( '|', array_keys( AbuseFilterParser::FUNCTIONS ) );
		// AbuseFilterTokenizer::KEYWORDS also includes constants (true, false and null),
		// but Ace redefines these constants afterwards so this will not be an issue
		$builderKeywords = implode( '|', AbuseFilterTokenizer::KEYWORDS );
		// Extract operators from tokenizer like we do in AbuseFilterParserTest
		$operators = implode( '|', array_map( function ( $op ) {
			return preg_quote( $op, '/' );
		}, AbuseFilterTokenizer::OPERATORS ) );
		$deprecatedVariables = implode( '|', array_keys( $deprecatedVars ) );
		$disabledVariables = implode( '|', array_keys( $this->keywordsManager->getDisabledVariables() ) );

		return [
			'variables' => $builderVariables,
			'functions' => $builderFunctions,
			'keywords' => $builderKeywords,
			'operators' => $operators,
			'deprecated' => $deprecatedVariables,
			'disabled' => $disabledVariables,
			'aceReadOnly' => !$canEdit
		];
	}

}
