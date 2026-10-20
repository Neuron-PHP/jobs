<?php

namespace Neuron\Jobs\Bootstrap;

/**
 * Finds the application configuration directory for a CLI process.
 *
 * @package Neuron\Jobs\Bootstrap
 */
class ConfigLocator
{
	/**
	 * Resolve the configuration directory.
	 *
	 * An explicit path wins. Otherwise the usual locations relative to the
	 * working directory are tried, matching what bin/neuron itself does when it
	 * loads neuron.yaml. A directory holding neuron.yaml is preferred, but one
	 * without it is still accepted: a container configured entirely through
	 * environment variables ships its other configuration (schedule, routes,
	 * initializers' settings) in config/ without a neuron.yaml, and the
	 * application still has to boot from there.
	 *
	 * @param string|null $explicit Path given on the command line, if any
	 * @param string|null $cwd Working directory (defaults to getcwd())
	 * @return string|null Absolute directory path, or null if none found
	 */
	public static function locate( ?string $explicit = null, ?string $cwd = null ): ?string
	{
		if( $explicit !== null && $explicit !== '' )
		{
			return is_dir( $explicit ) ? self::absolute( $explicit ) : null;
		}

		$cwd = $cwd ?? getcwd();

		$candidates = array_filter(
			[
				$cwd . '/config',
				dirname( $cwd ) . '/config',
				dirname( $cwd, 2 ) . '/config',
			],
			'is_dir'
		);

		foreach( $candidates as $candidate )
		{
			if( file_exists( $candidate . '/neuron.yaml' ) )
			{
				return self::absolute( $candidate );
			}
		}

		foreach( $candidates as $candidate )
		{
			return self::absolute( $candidate );
		}

		return null;
	}

	private static function absolute( string $path ): string
	{
		return rtrim( realpath( $path ) ?: $path, '/' );
	}
}
