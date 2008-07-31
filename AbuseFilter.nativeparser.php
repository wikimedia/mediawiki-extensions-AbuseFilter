<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();

class AbuseFilterParserNative {
	var $mVars;
	var $mProcess,$mPipes;
	
	public function __destruct() {
		foreach( $this->mPipes as $pipe ) {
			fclose($pipe);
		}
		
		proc_close( $this->mProcess );
	}
	
	public function setVar( $name, $var ) {
		$this->mVars[$name] = $var;
	}
	
	public function setVars( $vars ) {
		foreach( $vars as $name => $var ) {
			$this->setVar( $name, $var );
		}
	}
	
	public function getNativeParser() {	
		global $wgAbuseFilterNativeParser;
		
		if (!is_resource($this->mProcess)) {
			$this->mPipes = array();
			$descriptorspec = array( 
					0 => array( 'pipe', 'r' ),
					1 => array( 'pipe', 'w' )
				);
				
			$this->mProcess = proc_open( $wgAbuseFilterNativeParser, $descriptorspec, $this->mPipes );
			
			if (!is_resource($this->mProcess)) {
				throw new MWException( "Error using native parser" );
			}
			
			return $this->mPipes;
		}
		
		return $this->mPipes;
	}
	
	public function parse( $filter ) {
		$request = $this->generateXMLRequest( $filter );
		
		$pipes = $this->getNativeParser();
		
		if (is_array($pipes)) {
			fwrite($pipes[0], $request);
			fwrite($pipes[0], "\x04");
			fflush($pipes[0]);

			// Get response
			$response = trim(fgets( $pipes[1] ));
			
			if ($response == "MATCH") {
				return true;
			} elseif ($response == "NOMATCH") {
				return false;
			} else {
				throw new MWException( "Unknown output from native parser: $response" );
			}
		}
	}
	
	protected function generateXMLRequest( $filter ) {
		// Write vars
		$vars = '';
		foreach( $this->mVars as $key => $value ) {
			$vars .= Xml::element( 'var', array( 'key' => $key ), utf8_encode($value) );
		}
		$vars = Xml::tags( 'vars', null, $vars );
		
		$code = Xml::element( 'rule', null, utf8_encode($filter) );
		
		$request = Xml::tags( 'request', null, $vars . $code );
		
		return $request;
	}
}