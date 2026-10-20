<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Core\Registry\RegistryKeys;
use Neuron\Data\Settings\Source\ISettingSource;
use Neuron\Jobs\Bootstrap\ConfigLocator;
use Neuron\Jobs\Queue\Worker;
use Neuron\Patterns\Registry;

/**
 * Ask every running queue worker to exit after its current job.
 *
 * Touches the restart file that `jobs:work` checks between jobs. Workers
 * started before the touch exit with code 0; the service manager (systemd,
 * supervisor, or `jobs:run`) starts them again on the new code. Workers
 * started afterwards are unaffected, so this is safe to run from a deploy
 * script at any time.
 *
 * The file lives at `queue.restart_file` in neuron.yaml, or
 * `<site>/storage/jobs.restart` by default. Workers on another host need a
 * shared filesystem for this to reach them; otherwise restart them there.
 */
class RestartCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'jobs:restart';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Signal running queue workers to restart after their current job';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'config', 'c', true, 'Path to configuration directory (located automatically when omitted)' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		$path = self::resolveRestartFile( $this->input->getOption( 'config' ) );

		if( $path === null )
		{
			$this->output->error( 'Cannot determine the restart file: no configuration directory found. Use --config.' );
			return 1;
		}

		$dir = dirname( $path );

		if( !is_dir( $dir ) && !@mkdir( $dir, 0775, true ) && !is_dir( $dir ) )
		{
			$this->output->error( "Cannot create directory: {$dir}" );
			return 1;
		}

		// touch() on an existing file only bumps mtime, which is all the
		// workers look at. Sleep to the next whole second first so a worker
		// that started this same second still sees "newer".
		$now = time();

		if( file_exists( $path ) && filemtime( $path ) >= $now )
		{
			sleep( 1 );
		}

		if( !@touch( $path ) )
		{
			$this->output->error( "Cannot write restart file: {$path}" );
			return 1;
		}

		$this->output->success( "Restart requested: {$path}" );
		$this->output->info( 'Workers will exit after their current job; your service manager restarts them.' );

		return 0;
	}

	/**
	 * Where the restart file lives for this site.
	 *
	 * `queue.restart_file` (absolute, or relative to the site root) wins;
	 * otherwise Worker::defaultRestartFile() under the site root, which is
	 * the parent of the config directory.
	 *
	 * @param string|null $configOption --config value, if given
	 * @param ISettingSource|null $settings Defaults to the Registry
	 * @return string|null Null when no site root can be found
	 */
	public static function resolveRestartFile( ?string $configOption = null, ?ISettingSource $settings = null ): ?string
	{
		$settings = $settings ?? self::settingsFromRegistry();

		$configured = $settings?->get( 'queue', 'restart_file' );
		$configPath = ConfigLocator::locate( $configOption );
		$basePath = $configPath !== null ? dirname( $configPath ) : null;

		if( is_string( $configured ) && $configured !== '' )
		{
			if( str_starts_with( $configured, '/' ) || $basePath === null )
			{
				return $configured;
			}

			return rtrim( $basePath, '/' ) . '/' . ltrim( $configured, '/' );
		}

		if( $basePath === null )
		{
			$registryBase = Registry::getInstance()->get( RegistryKeys::BASE_PATH );
			$basePath = is_string( $registryBase ) && $registryBase !== '' && $registryBase !== '.' ? $registryBase : null;
		}

		return $basePath !== null ? Worker::defaultRestartFile( $basePath ) : null;
	}

	/**
	 * @return ISettingSource|null
	 */
	private static function settingsFromRegistry(): ?ISettingSource
	{
		$settings = Registry::getInstance()->get( RegistryKeys::SETTINGS );

		return $settings instanceof ISettingSource ? $settings : null;
	}
}
