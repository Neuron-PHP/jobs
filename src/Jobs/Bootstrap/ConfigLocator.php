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
	 * loads neuron.yaml.
	 *
	 * @param string|null $explicit Path given on the command line, if any
	 * @param string|null $cwd Working directory (defaults to getcwd())
	 * @return string|null Absolute directory path, or null if none found
	 */
	public static function locate( ?string $explicit = null, ?string $cwd = null ): ?string
	{
		if( $explicit !== null && $explicit !== '' )
		{
			return is_dir( $explicit ) ? rtrim( realpath( $explicit ) ?: $explicit, '/' ) : null;
		}

		$cwd = $cwd ?? getcwd();

		$candidates = [
			$cwd . '/config',
			dirname( $cwd ) . '/config',
			dirname( $cwd, 2 ) . '/config',
		];

		foreach( $candidates as $candidate )
		{
			if( is_dir( $candidate ) && file_exists( $candidate . '/neuron.yaml' ) )
			{
				return rtrim( realpath( $candidate ) ?: $candidate, '/' );
			}
		}

		return null;
	}
}
