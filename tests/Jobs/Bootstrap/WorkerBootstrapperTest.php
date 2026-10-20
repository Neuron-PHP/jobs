<?php

namespace Tests\Jobs\Bootstrap;

use Neuron\Application\Base;
use Neuron\Core\Registry\RegistryKeys;
use Neuron\Data\Settings\Source\Memory;
use Neuron\Jobs\Bootstrap\ConfigLocator;
use Neuron\Jobs\Bootstrap\IWorkerBootstrap;
use Neuron\Jobs\Bootstrap\MinimalApplication;
use Neuron\Jobs\Bootstrap\WorkerBootstrapper;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Application that records lifecycle calls.
 */
class RecordingApplication extends Base
{
	public static int $Started = 0;
	public static int $Finished = 0;
	public static bool $RefuseStart = false;

	protected function onStart(): bool
	{
		self::$Started++;
		return !self::$RefuseStart;
	}

	protected function onRun(): void
	{
	}

	protected function onFinish(): void
	{
		self::$Finished++;
	}
}

/**
 * Bootstrap that hands back a RecordingApplication.
 */
class RecordingBootstrap implements IWorkerBootstrap
{
	public static ?string $ConfigPath = null;

	public function boot( string $configPath ): ?Base
	{
		self::$ConfigPath = $configPath;
		return new RecordingApplication( '1.0.0' );
	}
}

/**
 * Bootstrap that declines to produce an application.
 */
class NullBootstrap implements IWorkerBootstrap
{
	public function boot( string $configPath ): ?Base
	{
		return null;
	}
}

/**
 * Bootstrap that blows up.
 */
class ExplodingBootstrap implements IWorkerBootstrap
{
	public function boot( string $configPath ): ?Base
	{
		throw new \RuntimeException( 'boot exploded' );
	}
}

/**
 * Looks like a bootstrap in config but is not one.
 */
class NotABootstrap
{
}

class WorkerBootstrapperTest extends TestCase
{
	private mixed $previousSettings;
	private string $configDir;

	protected function setUp(): void
	{
		parent::setUp();

		$this->previousSettings = Registry::getInstance()->get( RegistryKeys::SETTINGS );

		// A config directory with a neuron.yaml so ConfigLocator accepts it.
		$this->configDir = sys_get_temp_dir() . '/worker_bootstrap_' . uniqid() . '/config';
		mkdir( $this->configDir, 0777, true );
		file_put_contents( $this->configDir . '/neuron.yaml', "system:\n  timezone: UTC\n" );

		RecordingApplication::$Started = 0;
		RecordingApplication::$Finished = 0;
		RecordingApplication::$RefuseStart = false;
		RecordingBootstrap::$ConfigPath = null;
	}

	protected function tearDown(): void
	{
		Registry::getInstance()->set( RegistryKeys::SETTINGS, $this->previousSettings );

		@unlink( $this->configDir . '/neuron.yaml' );
		@rmdir( $this->configDir );
		@rmdir( dirname( $this->configDir ) );

		parent::tearDown();
	}

	private function settings( array $queue = [] ): Memory
	{
		return new Memory( [ 'queue' => $queue ] );
	}

	public function testBootFallsBackToMinimalApplicationWithoutConfiguredBootstrap(): void
	{
		$bootstrapper = new WorkerBootstrapper();

		$app = $bootstrapper->boot( 'worker', $this->configDir, $this->settings() );

		$this->assertInstanceOf( MinimalApplication::class, $app );
		$this->assertSame( $app, $bootstrapper->getApplication() );
		$this->assertEquals( dirname( realpath( $this->configDir ) ), $app->getBasePath() );

		$bootstrapper->shutdown();
		$this->assertNull( $bootstrapper->getApplication() );
	}

	public function testBootUsesConfiguredBootstrapAndStartsIt(): void
	{
		$bootstrapper = new WorkerBootstrapper();

		$app = $bootstrapper->boot(
			'scheduler',
			$this->configDir,
			$this->settings( [ 'bootstrap' => RecordingBootstrap::class ] )
		);

		$this->assertInstanceOf( RecordingApplication::class, $app );
		$this->assertEquals( 1, RecordingApplication::$Started, 'Base::start() must run onStart()' );
		$this->assertEquals( realpath( $this->configDir ), RecordingBootstrap::$ConfigPath );
		$this->assertEquals( dirname( realpath( $this->configDir ) ), $app->getBasePath() );
	}

	public function testBootAcceptsLeadingBackslashInClassName(): void
	{
		$bootstrapper = new WorkerBootstrapper();

		$app = $bootstrapper->boot(
			'worker',
			$this->configDir,
			$this->settings( [ 'bootstrap' => '\\' . RecordingBootstrap::class ] )
		);

		$this->assertInstanceOf( RecordingApplication::class, $app );
	}

	public function testShutdownRunsFinishOnlyForStartedApplication(): void
	{
		$bootstrapper = new WorkerBootstrapper();
		$bootstrapper->boot( 'worker', $this->configDir, $this->settings( [ 'bootstrap' => RecordingBootstrap::class ] ) );

		$bootstrapper->shutdown();
		$bootstrapper->shutdown();

		$this->assertEquals( 1, RecordingApplication::$Finished );
		$this->assertNull( $bootstrapper->getApplication() );
	}

	public function testBootReturnsNullWhenApplicationRefusesToStart(): void
	{
		RecordingApplication::$RefuseStart = true;
		$bootstrapper = new WorkerBootstrapper();

		$app = $bootstrapper->boot( 'worker', $this->configDir, $this->settings( [ 'bootstrap' => RecordingBootstrap::class ] ) );

		$this->assertNull( $app );
		$this->assertNull( $bootstrapper->getApplication() );

		$bootstrapper->shutdown();
		$this->assertEquals( 0, RecordingApplication::$Finished, 'finish() must not run for an application that never started' );
	}

	public function testBootReturnsNullWhenBootstrapReturnsNothing(): void
	{
		$bootstrapper = new WorkerBootstrapper();

		$this->assertNull( $bootstrapper->boot( 'worker', $this->configDir, $this->settings( [ 'bootstrap' => NullBootstrap::class ] ) ) );
	}

	public function testBootReturnsNullWhenBootstrapThrows(): void
	{
		$bootstrapper = new WorkerBootstrapper();

		$this->assertNull( $bootstrapper->boot( 'worker', $this->configDir, $this->settings( [ 'bootstrap' => ExplodingBootstrap::class ] ) ) );
	}

	public function testBootFallsBackWhenBootstrapClassIsMissing(): void
	{
		$bootstrapper = new WorkerBootstrapper();

		$app = $bootstrapper->boot( 'worker', $this->configDir, $this->settings( [ 'bootstrap' => 'No\\Such\\Bootstrap' ] ) );

		$this->assertInstanceOf( MinimalApplication::class, $app );
	}

	public function testBootFallsBackWhenBootstrapClassDoesNotImplementInterface(): void
	{
		$bootstrapper = new WorkerBootstrapper();

		$app = $bootstrapper->boot( 'worker', $this->configDir, $this->settings( [ 'bootstrap' => NotABootstrap::class ] ) );

		$this->assertInstanceOf( MinimalApplication::class, $app );
	}

	public function testBootReturnsNullWhenBootstrapConfiguredButNoConfigDirectory(): void
	{
		$bootstrapper = new WorkerBootstrapper();

		$app = $bootstrapper->boot(
			'worker',
			sys_get_temp_dir() . '/definitely_missing_' . uniqid(),
			$this->settings( [ 'bootstrap' => RecordingBootstrap::class ] )
		);

		$this->assertNull( $app );
		$this->assertEquals( 0, RecordingApplication::$Started );
	}

	public function testBootReadsSettingsFromRegistryWhenNotGiven(): void
	{
		// bin/neuron stores a SettingManager, which is also what Base expects.
		Registry::getInstance()->set(
			RegistryKeys::SETTINGS,
			new \Neuron\Data\Settings\SettingManager( $this->settings( [ 'bootstrap' => RecordingBootstrap::class ] ) )
		);

		$bootstrapper = new WorkerBootstrapper();

		$this->assertInstanceOf( RecordingApplication::class, $bootstrapper->boot( 'worker', $this->configDir ) );
	}

	public function testConfigLocatorPrefersExplicitPath(): void
	{
		$this->assertEquals( realpath( $this->configDir ), ConfigLocator::locate( $this->configDir ) );
		$this->assertNull( ConfigLocator::locate( $this->configDir . '/nope' ) );
	}

	public function testConfigLocatorSearchesUpFromWorkingDirectory(): void
	{
		$siteRoot = dirname( $this->configDir );
		$expected = realpath( $this->configDir );

		$this->assertEquals( $expected, ConfigLocator::locate( null, $siteRoot ) );
		$this->assertEquals( $expected, ConfigLocator::locate( null, $siteRoot . '/public' ) );
		$this->assertEquals( $expected, ConfigLocator::locate( null, $siteRoot . '/public/deeper' ) );
		$this->assertNull( ConfigLocator::locate( null, $siteRoot . '/a/b/c/d' ) );
	}

	public function testConfigLocatorAcceptsConfigDirectoryWithoutNeuronYaml(): void
	{
		// All settings from the environment: config/ exists for the schedule and
		// the like, but holds no neuron.yaml. The application must still boot.
		unlink( $this->configDir . '/neuron.yaml' );

		$this->assertEquals( realpath( $this->configDir ), ConfigLocator::locate( null, dirname( $this->configDir ) ) );
	}

	public function testConfigLocatorPrefersDirectoryHoldingNeuronYaml(): void
	{
		// A bare config/ nearer the working directory must not shadow the one
		// that actually holds neuron.yaml further up.
		$nearer = dirname( $this->configDir ) . '/public/config';
		mkdir( $nearer, 0777, true );

		try
		{
			$this->assertEquals( realpath( $this->configDir ), ConfigLocator::locate( null, dirname( $nearer ) ) );
		}
		finally
		{
			rmdir( $nearer );
			rmdir( dirname( $nearer ) );
		}
	}
}
