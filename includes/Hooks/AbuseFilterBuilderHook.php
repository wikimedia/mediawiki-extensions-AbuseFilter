<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

interface AbuseFilterBuilderHook {
	/**
	 * Hook runner for the `AbuseFilter-builder` hook
	 *
	 * Allows overwriting of the builder values returned by AbuseFilter::getBuilderValues
	 *
	 * @param array &$realValues Builder values
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onAbuseFilterBuilder( array &$realValues );
}
