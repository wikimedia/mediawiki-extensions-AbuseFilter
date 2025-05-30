<?php

namespace MediaWiki\Extension\AbuseFilter\Variables;

use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Html\Html;
use MessageLocalizer;

/**
 * Pretty-prints the content of a VariableHolder for use e.g. in AbuseLog hit details
 */
class VariablesFormatter {
	public const SERVICE_NAME = 'AbuseFilterVariablesFormatter';

	/** @var KeywordsManager */
	private $keywordsManager;
	/** @var VariablesManager */
	private $varManager;
	/** @var MessageLocalizer */
	private $messageLocalizer;

	/**
	 * @param KeywordsManager $keywordsManager
	 * @param VariablesManager $variablesManager
	 * @param MessageLocalizer $messageLocalizer
	 */
	public function __construct(
		KeywordsManager $keywordsManager,
		VariablesManager $variablesManager,
		MessageLocalizer $messageLocalizer
	) {
		$this->keywordsManager = $keywordsManager;
		$this->varManager = $variablesManager;
		$this->messageLocalizer = $messageLocalizer;
	}

	public function setMessageLocalizer( MessageLocalizer $messageLocalizer ): void {
		$this->messageLocalizer = $messageLocalizer;
	}

	public function buildVarDumpTable( VariableHolder $varHolder ): string {
		$vars = $this->varManager->exportAllVars( $varHolder );

		$output = '';

		// Now, build the body of the table.
		foreach ( $vars as $key => $value ) {
			$key = strtolower( $key );

			$varMsgKey = $this->keywordsManager->getMessageKeyForVar( $key );
			if ( $varMsgKey ) {
				$varMsg = $this->messageLocalizer->msg( $varMsgKey );
				$arg = Html::element( 'code', [], $key );
				if ( str_contains( $varMsg->plain(), '$1' ) ) {
					$keyDisplay = $varMsg->params( $arg )->parse();
				} else {
					// workaround due to 1904cf8 (temporary?)
					$keyDisplay = $varMsg->parse() . ' '
						. $this->messageLocalizer->msg( 'parentheses' )->rawParams( $arg )->escaped();
				}
			} else {
				$keyDisplay = Html::element( 'code', [], $key );
			}

			$value = Html::element(
				'div',
				[ 'class' => 'mw-abuselog-var-value' ],
				self::formatVar( $value )
			);

			$trow =
				Html::rawElement( 'td', [ 'class' => 'mw-abuselog-var' ], $keyDisplay ) .
				Html::rawElement( 'td', [ 'class' => 'mw-abuselog-var-value' ], $value );
			$output .=
				Html::rawElement( 'tr',
					[ 'class' => "mw-abuselog-details-$key mw-abuselog-value" ], $trow
				) . "\n";
		}

		return Html::rawElement( 'table', [ 'class' => 'mw-abuselog-details' ],
			Html::rawElement( 'thead', [],
				Html::rawElement( 'tr', [],
					Html::element( 'th', [],
						$this->messageLocalizer->msg( 'abusefilter-log-details-var' )->text()
					) .
					Html::element( 'th', [],
						$this->messageLocalizer->msg( 'abusefilter-log-details-val' )->text()
					)
				)
			) .
			Html::rawElement( 'tbody', [], $output )
		);
	}

	/**
	 * @param mixed $var
	 * @param string $indent
	 * @return string
	 */
	public static function formatVar( $var, string $indent = '' ): string {
		if ( $var === [] ) {
			return '[]';
		} elseif ( is_array( $var ) ) {
			$ret = '[';
			$indent .= "\t";
			foreach ( $var as $key => $val ) {
				$ret .= "\n$indent" . self::formatVar( $key, $indent ) .
					' => ' . self::formatVar( $val, $indent ) . ',';
			}
			// Strip trailing commas
			return substr( $ret, 0, -1 ) . "\n" . substr( $indent, 0, -1 ) . ']';
		} elseif ( is_string( $var ) ) {
			// Don't escape the string (specifically backslashes) to avoid displaying wrong stuff
			return "'$var'";
		} elseif ( $var === null ) {
			return 'null';
		} elseif ( is_float( $var ) ) {
			// Don't let float precision produce weirdness
			return (string)$var;
		}
		return var_export( $var, true );
	}
}
