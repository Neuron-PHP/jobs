<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Application\Base;
use Neuron\Application\CrossCutting\Event;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Core\Registry\RegistryKeys;
use Neuron\Events\IEvent;
use Neuron\Events\IListener;
use Neuron\Jobs\Bootstrap\IWorkerBootstrap;
use Neuron\Jobs\Bootstrap\WorkerBootstrapper;
use Neuron\Jobs\Cli\Commands\ScheduleCommand;
use Neuron\Jobs\IJob;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

/**
 * What the probes saw.
 */
class ScheduleContextProbe
{
	public static int $Heard = 0;
	public static int $Initialized = 0;
}

class ScheduleContextEvent implements IEvent
{
}

class ScheduleContextListener implements IListener
{
	public function event( IEvent $event )
	{
		ScheduleContextProbe::$Heard++;
	}
}

/**
 * The scheduled job: emits one event.
 */
class ScheduleContextJob implements IJob
{
	public function getName(): string
	{
		return 'ScheduleContextJob';
	}

	public function run( array $argv = [] ): mixed
	{
		Event::emit( new ScheduleContextEvent() );

		return true;
	}
}

/**
 * A full application: Base::onStart() loads the listeners and runs the
 * initializers, as an application named by queue.bootstrap does.
 */
class ScheduleContextApplication extends Base
{
	protected function onRun(): void
	{
	}
}

class ScheduleContextBootstrap implements IWorkerBootstrap
{
	public function boot( string $configPath ): ?Base
	{
		$app = new ScheduleContextApplication( '1.0.0' );
		$app->setBasePath( dirname( $configPath ) );

		return $app;
	}
}

/**
 * jobs:schedule boots an application context and then starts the scheduler,
 * which is an application too. Each used to load the event listeners, so
 * every event reached every listener twice, and the initializers ran twice.
 */
class ScheduleCommandContextTest extends TestCase
{
	private string $Dir;
	private string $Initializer;

	protected function setUp(): void
	{
		parent::setUp();

		ScheduleContextProbe::$Heard       = 0;
		ScheduleContextProbe::$Initialized = 0;

		Event::invalidate();
		Registry::getInstance()->set( RegistryKeys::SETTINGS, null );
		Registry::getInstance()->set( 'Initializers.Namespace', null );

		$this->Dir = sys_get_temp_dir() . '/schedule_context_' . uniqid();

		mkdir( $this->Dir . '/config', 0777, true );
		mkdir( $this->Dir . '/app/Initializers', 0777, true );

		file_put_contents( $this->Dir . '/config/schedule.yaml', "schedule:\n  probe:\n    class: '" . ScheduleContextJob::class . "'\n    cron: '* * * * *'\n" );

		file_put_contents( $this->Dir . '/config/event-listeners.yaml', "events:\n  probe:\n    class: '" . ScheduleContextEvent::class . "'\n    listeners:\n      - '" . ScheduleContextListener::class . "'\n" );

		// A class name of its own per test: the runner require_once()s each
		// file, and a second file declaring the same class would be fatal.
		$this->Initializer = 'ScheduleContextInitializer' . bin2hex( random_bytes( 4 ) );

		file_put_contents( $this->Dir . '/app/Initializers/' . $this->Initializer . '.php', <<<PHP
<?php

namespace App\\Initializers;

class {$this->Initializer}
{
	public function run(): void
	{
		\\Tests\\Jobs\\Cli\\Commands\\ScheduleContextProbe::\$Initialized++;
	}
}
PHP );
	}

	protected function tearDown(): void
	{
		Event::invalidate();
		Registry::getInstance()->set( RegistryKeys::SETTINGS, null );
		Registry::getInstance()->set( 'queue.manager', null );

		foreach( [ 'app/Initializers/' . $this->Initializer . '.php', 'config/schedule.yaml', 'config/event-listeners.yaml' ] as $file )
		{
			@unlink( $this->Dir . '/' . $file );
		}

		@rmdir( $this->Dir . '/app/Initializers' );
		@rmdir( $this->Dir . '/app' );
		@rmdir( $this->Dir . '/config' );
		@rmdir( $this->Dir );

		parent::tearDown();
	}

	private function poll( ?WorkerBootstrapper $bootstrapper, bool $noBootstrap = false ): int
	{
		$command = new ScheduleCommand();
		$command->configure();

		if( $bootstrapper !== null )
		{
			$command->setBootstrapper( $bootstrapper );
		}

		$input = new Input();
		$input->setOption( 'poll', true );
		$input->setOption( 'config', $this->Dir . '/config' );

		if( $noBootstrap )
		{
			$input->setOption( 'no-bootstrap', true );
		}

		$reflection = new \ReflectionClass( $command );
		$reflection->getProperty( 'input' )->setValue( $command, $input );
		$reflection->getProperty( 'output' )->setValue( $command, new Output() );

		// The scheduler finds config/event-listeners.yaml and app/Initializers
		// from the working directory, as it does in a deployed site's root.
		$cwd = getcwd();
		chdir( $this->Dir );

		try
		{
			return $command->execute();
		}
		finally
		{
			chdir( $cwd );
		}
	}

	/**
	 * A bootstrapper whose queue.bootstrap is ScheduleContextBootstrap.
	 */
	private function fullBootstrapper(): WorkerBootstrapper
	{
		return new class extends WorkerBootstrapper {
			public function boot( string $role, ?string $configPath = null, ?\Neuron\Data\Settings\Source\ISettingSource $settings = null ): ?Base
			{
				return parent::boot( $role, $configPath, new \Neuron\Data\Settings\Source\Memory( [
					'queue' => [ 'bootstrap' => ScheduleContextBootstrap::class ],
				] ) );
			}
		};
	}

	public function testWithAFullApplicationEachEventIsHeardOnceAndInitializersRunOnce(): void
	{
		$this->assertSame( 0, $this->poll( $this->fullBootstrapper() ) );

		$this->assertSame( 1, ScheduleContextProbe::$Heard, 'one event, one delivery' );
		$this->assertSame( 1, ScheduleContextProbe::$Initialized );
	}

	/**
	 * A MinimalApplication (no queue.bootstrap) loads the listeners but runs no
	 * initializers, so the scheduler still runs them.
	 */
	public function testWithAMinimalApplicationTheSchedulerStillRunsTheInitializers(): void
	{
		$this->assertSame( 0, $this->poll( new WorkerBootstrapper() ) );

		$this->assertSame( 1, ScheduleContextProbe::$Heard );
		$this->assertSame( 1, ScheduleContextProbe::$Initialized );
	}

	public function testWithoutABootstrapTheSchedulerLoadsEverythingItself(): void
	{
		$this->assertSame( 0, $this->poll( null, noBootstrap: true ) );

		$this->assertSame( 1, ScheduleContextProbe::$Heard );
		$this->assertSame( 1, ScheduleContextProbe::$Initialized );
	}
}
