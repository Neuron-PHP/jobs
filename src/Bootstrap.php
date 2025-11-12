<?php

use Neuron\Data\Object\Version;
use Neuron\Data\Setting\Source\Yaml;
use Neuron\Jobs\Scheduler;

/**
 * Initialize the application.
 *
 * @param string $ConfigPath
 * @return ?Scheduler
 * @throws Exception
 */
function Boot( string $ConfigPath ) : ?Scheduler
{
	try
	{
		$Settings = new Yaml( "$ConfigPath/neuron.yaml" );
	}
	catch( Exception $e )
	{
		$Settings = null;
	}

	$Version = new Version();
	$Version->loadFromFile( __DIR__."/../.version.json" );

	return new Scheduler( $Version->getAsString(), $Settings );
}

/**
 * Run the application.
 *
 * @param Scheduler $App
 * @param array $argv
 * @throws Exception
 */
function Scheduler( Scheduler $App, array $argv ) : void
{
	$App->run( $argv );
}
