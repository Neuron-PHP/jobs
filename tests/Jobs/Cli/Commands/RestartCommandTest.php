<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Core\Registry\RegistryKeys;
use Neuron\Data\Settings\Source\Memory;
use Neuron\Jobs\Cli\Commands\RestartCommand;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class RestartCommandTest extends TestCase
{
	private string $siteRoot;
	private mixed $previousSettings;
	private mixed $previousBasePath;

	protected function setUp(): void
	{
		parent::setUp();

		$this->siteRoot = sys_get_temp_dir() . '/restart_cmd_' . uniqid();
		mkdir( $this->siteRoot . '/config', 0777, true );
		file_put_contents( $this->siteRoot . '/config/neuron.yaml', "system:\n  timezone: UTC\n" );

		$registry = Registry::getInstance();
		$this->previousSettings = $registry->get( RegistryKeys::SETTINGS );
		$this->previousBasePath = $registry->get( RegistryKeys::BASE_PATH );
		$registry->set( RegistryKeys::SETTINGS, null );
		$registry->set( RegistryKeys::BASE_PATH, null );
	}

	protected function tearDown(): void
	{
		$registry = Registry::getInstance();
		$registry->set( RegistryKeys::SETTINGS, $this->previousSettings );
		$registry->set( RegistryKeys::BASE_PATH, $this->previousBasePath );

		foreach( [ '/storage/jobs.restart', '/custom.flag', '/config/neuron.yaml' ] as $file )
		{
			@unlink( $this->siteRoot . $file );
		}

		@rmdir( $this->siteRoot . '/storage' );
		@rmdir( $this->siteRoot . '/config' );
		@rmdir( $this->siteRoot );

		parent::tearDown();
	}

	private function command( array $options = [] ): RestartCommand
	{
		$command = new RestartCommand();
		$command->configure();

		$input = new Input();
		foreach( $options as $key => $value )
		{
			$input->setOption( $key, $value );
		}

		$reflection = new \ReflectionClass( $command );
		$reflection->getProperty( 'input' )->setValue( $command, $input );
		$reflection->getProperty( 'output' )->setValue( $command, new Output() );

		return $command;
	}

	public function testNameAndOptions(): void
	{
		$command = $this->command();

		$this->assertEquals( 'jobs:restart', $command->getName() );
		$this->assertNotEmpty( $command->getDescription() );
	}

	public function testResolveRestartFileDefaultsToStorageUnderSiteRoot(): void
	{
		$expected = realpath( $this->siteRoot ) . '/storage/jobs.restart';

		$this->assertEquals( $expected, RestartCommand::resolveRestartFile( $this->siteRoot . '/config' ) );
	}

	public function testResolveRestartFileHonoursRelativeSetting(): void
	{
		$settings = new Memory( [ 'queue' => [ 'restart_file' => 'custom.flag' ] ] );

		$this->assertEquals(
			realpath( $this->siteRoot ) . '/custom.flag',
			RestartCommand::resolveRestartFile( $this->siteRoot . '/config', $settings )
		);
	}

	public function testResolveRestartFileHonoursAbsoluteSetting(): void
	{
		$settings = new Memory( [ 'queue' => [ 'restart_file' => '/var/run/neuron.restart' ] ] );

		$this->assertEquals( '/var/run/neuron.restart', RestartCommand::resolveRestartFile( $this->siteRoot . '/config', $settings ) );
	}

	public function testResolveRestartFileFallsBackToRegistryBasePath(): void
	{
		Registry::getInstance()->set( RegistryKeys::BASE_PATH, '/srv/app' );

		$this->assertEquals( '/srv/app/storage/jobs.restart', RestartCommand::resolveRestartFile( sys_get_temp_dir() . '/nope_' . uniqid() ) );
	}

	public function testResolveRestartFileReturnsNullWithNothingToGoOn(): void
	{
		$this->assertNull( RestartCommand::resolveRestartFile( sys_get_temp_dir() . '/nope_' . uniqid() ) );
	}

	public function testExecuteCreatesStorageDirectoryAndTouchesFile(): void
	{
		$command = $this->command( [ 'config' => $this->siteRoot . '/config' ] );

		ob_start();
		$exit = $command->execute();
		ob_end_clean();

		$file = $this->siteRoot . '/storage/jobs.restart';

		$this->assertEquals( 0, $exit );
		$this->assertFileExists( $file );
		$this->assertEqualsWithDelta( time(), filemtime( $file ), 2 );
	}

	public function testExecuteBumpsMtimeOfExistingFile(): void
	{
		mkdir( $this->siteRoot . '/storage' );
		$file = $this->siteRoot . '/storage/jobs.restart';
		touch( $file, time() - 3600 );

		$command = $this->command( [ 'config' => $this->siteRoot . '/config' ] );

		ob_start();
		$exit = $command->execute();
		ob_end_clean();

		$this->assertEquals( 0, $exit );
		$this->assertEqualsWithDelta( time(), filemtime( $file ), 2 );
	}

	public function testExecuteFailsWithoutAnySiteRoot(): void
	{
		$command = $this->command( [ 'config' => sys_get_temp_dir() . '/nope_' . uniqid() ] );

		ob_start();
		$exit = $command->execute();
		ob_end_clean();

		$this->assertEquals( 1, $exit );
	}
}
