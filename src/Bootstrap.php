<?php

use Neuron\Data\Object\Version;
use Neuron\Data\Setting\SettingManager;
use Neuron\Data\Setting\Source\Env;
use Neuron\Data\Setting\Source\Yaml;
use Neuron\Jobs\Scheduler;
use Neuron\Patterns\Registry;

/**
 * Initialize the application.
 *
 * @param string $ConfigPath
 * @return ?Scheduler
 * @throws Exception
 */
function Boot( string $ConfigPath ) : ?Scheduler
{
	/** @var Neuron\Data\Setting\Source\ISettingSource $Settings */

	try
	{
		$Settings = new Yaml( "$ConfigPath/config.yaml" );
		$Manager  = new SettingManager( $Settings );
		$Fallback = new Env( Neuron\Data\Env::getInstance( "$ConfigPath/.env" ) );
		$Manager->setFallback( $Fallback );
	}
	catch( Exception $e )
	{
		echo "Failed to load $ConfigPath/config.yaml\r\n";
		return null;
	}

	Registry::getInstance()
			  ->set( 'Settings', $Manager );

	$Version = new Version();
	$Version->loadFromFile( __DIR__."/../version.json" );

	return new Scheduler( $Version->getAsString(), $Settings );
}
function Scheduler( Scheduler $App, $argv ) : void
{
	$App->run( $argv );
}
