<?php

use Neuron\Data\Object\Version;
use Neuron\Data\Setting\Source\Ini;
use Neuron\Data\Setting\Source\Yaml;
use Neuron\Jobs\Scheduler;
use Neuron\Patterns\Registry;

/**
 * Initialize the application.
 *
 * @param string $ConfigPath
 * @return Scheduler
 * @throws Exception
 */
function Boot( string $ConfigPath ) : Scheduler
{
	/** @var Neuron\Data\Setting\Source\ISettingSource $Settings */

	try
	{
		$Settings = new Yaml( "$ConfigPath/config.yaml" );
	}
	catch( Exception $e )
	{
		echo "Failed to load $ConfigPath/config.yaml\r\n";
		exit( 1 );
	}

	Registry::getInstance()
			  ->set( 'Settings', $Settings );

	$Version = new Version();
	$Version->loadFromFile( __DIR__."/../version.json" );

	return new Scheduler( $Version->getAsString(), $Settings );
}
function Scheduler( Scheduler $App, $argv ) : void
{
	try
	{
		$App->run( $argv );
	}
	catch( Exception $e )
	{
		echo 'Ouch.';
	}
}
