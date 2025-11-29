<?php

use Neuron\Data\Objects\Version;
use Neuron\Data\Settings\Source\Yaml;
use Neuron\Jobs\Scheduler;

/**
 * Initialize the application.
 *
 * @param string $ConfigPath
 * @return ?Scheduler
 * @throws Exception
 */
function boot( string $ConfigPath ) : ?Scheduler
{
	try
	{
		$Settings = new Yaml( "$ConfigPath/neuron.yaml" );
	}
	catch( Exception $e )
	{
		$Settings = null;
	}

	$version = \Neuron\Data\Factories\Version::fromFile( __DIR__."/../.version.json" );

	return new Scheduler( $version->getAsString(), $Settings );
}

/**
 * Run the application.
 *
 * @param Scheduler $App
 * @param array $argv
 * @throws Exception
 */
function scheduler( Scheduler $App, array $argv ) : void
{
	$App->run( $argv );
}
