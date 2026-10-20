<?php

namespace Neuron\Jobs\Bootstrap;

use Neuron\Application\Base;
use Neuron\Core\Registry\RegistryKeys;
use Neuron\Data\Settings\Source\ISettingSource;
use Neuron\Log\Log;
use Neuron\Patterns\Registry;

/**
 * Boots the application context for a worker or scheduler process.
 *
 * Reads `queue.bootstrap` from the application settings, asks that
 * IWorkerBootstrap for an application, and runs Base::start() so events,
 * initializers and any subclass wiring are live before the first job runs.
 * shutdown() runs Base::finish() when the process exits.
 *
 * With no `queue.bootstrap` configured a MinimalApplication is used, which
 * only loads event listeners. A warning is logged so a site that forgot to
 * configure its bootstrap finds out before its jobs start failing for want of
 * a container.
 *
 * Every log line written after boot carries a `role` context value
 * (`worker`, `scheduler`) so the three process roles sharing one log file can
 * be told apart.
 *
 * @package Neuron\Jobs\Bootstrap
 */
class WorkerBootstrapper
{
	public const SETTING_SECTION = 'queue';
	public const SETTING_NAME = 'bootstrap';

	private ?Base $_application = null;
	private bool $_started = false;

	/**
	 * Boot the application for this process.
	 *
	 * @param string $role Process role for log context: 'worker' or 'scheduler'
	 * @param string|null $configPath Configuration directory; located automatically when null
	 * @param ISettingSource|null $settings Settings to read `queue.bootstrap` from (defaults to the Registry)
	 * @return Base|null The started application, or null if none could be started
	 */
	public function boot( string $role, ?string $configPath = null, ?ISettingSource $settings = null ): ?Base
	{
		$settings = $settings ?? $this->settingsFromRegistry();
		$configPath = ConfigLocator::locate( $configPath );

		$bootstrap = $this->resolveBootstrap( $settings );

		if( $bootstrap === null )
		{
			Log::warning( 'No queue.bootstrap configured; running with event listeners only. Jobs needing the container or database should configure one.' );
			$this->_application = new MinimalApplication( '0.0.0', $settings );
		}
		else
		{
			if( $configPath === null )
			{
				Log::error( 'queue.bootstrap is configured but no configuration directory could be found; running without application context.' );
				return null;
			}

			try
			{
				$this->_application = $bootstrap->boot( $configPath );
			}
			catch( \Throwable $e )
			{
				Log::error( 'queue.bootstrap failed: ' . $e->getMessage() );
				return null;
			}

			if( $this->_application === null )
			{
				Log::warning( get_class( $bootstrap ) . '::boot() returned no application; running without application context.' );
				return null;
			}
		}

		// Base::initSettings() leaves the base path at '.' when settings were
		// already in the Registry (the CLI put them there), so anchor it to the
		// site root explicitly. Initializers and view paths resolve against it.
		if( $configPath !== null && $this->_application->getBasePath() === '.' )
		{
			$this->_application->setBasePath( dirname( $configPath ) );
		}

		// After the constructor: Base::initLogger() rebuilds the logger, and
		// the role tag must apply to the logger the process will actually use.
		Log::setContext( 'role', $role );

		if( !$this->_application->start() )
		{
			Log::error( 'Application refused to start; running without application context.' );
			$this->_application = null;
			return null;
		}

		$this->_started = true;

		Log::info( 'Application context ready for ' . $role . ' (' . get_class( $this->_application ) . ')' );

		return $this->_application;
	}

	/**
	 * Run the application's shutdown lifecycle, if one was started.
	 *
	 * @return void
	 */
	public function shutdown(): void
	{
		if( $this->_started && $this->_application !== null )
		{
			$this->_application->finish();
		}

		$this->_started = false;
		$this->_application = null;
	}

	/**
	 * @return Base|null
	 */
	public function getApplication(): ?Base
	{
		return $this->_application;
	}

	/**
	 * Instantiate the configured IWorkerBootstrap, if any.
	 *
	 * @param ISettingSource|null $settings
	 * @return IWorkerBootstrap|null
	 */
	private function resolveBootstrap( ?ISettingSource $settings ): ?IWorkerBootstrap
	{
		$class = $settings?->get( self::SETTING_SECTION, self::SETTING_NAME );

		if( !is_string( $class ) || $class === '' )
		{
			return null;
		}

		$class = ltrim( $class, '\\' );

		if( !class_exists( $class ) )
		{
			Log::error( "queue.bootstrap class not found: {$class}" );
			return null;
		}

		$bootstrap = new $class();

		if( !$bootstrap instanceof IWorkerBootstrap )
		{
			Log::error( "queue.bootstrap must implement " . IWorkerBootstrap::class . ": {$class}" );
			return null;
		}

		return $bootstrap;
	}

	/**
	 * @return ISettingSource|null
	 */
	private function settingsFromRegistry(): ?ISettingSource
	{
		$settings = Registry::getInstance()->get( RegistryKeys::SETTINGS );

		return $settings instanceof ISettingSource ? $settings : null;
	}
}
