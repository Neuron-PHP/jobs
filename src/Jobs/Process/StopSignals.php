<?php

namespace Neuron\Jobs\Process;

/**
 * The signals a long-running jobs process treats as "stop".
 *
 * SIGTERM and SIGINT always. SIGWINCH too, but only when no terminal is
 * attached: the official `php:*-apache` Docker image declares
 * `STOPSIGNAL SIGWINCH` (Apache's graceful stop), so every runtime that
 * honours the image config - docker stop, Kubernetes, ECS, Railway - sends a
 * worker or scheduler SIGWINCH on shutdown. PHP ignores it by default and the
 * process is SIGKILLed when the grace period ends, mid-job. In a terminal
 * SIGWINCH just means the window was resized, so it is ignored there.
 *
 * @package Neuron\Jobs\Process
 */
class StopSignals
{
	private static ?bool $_assumeHeadless = null;

	/**
	 * Route the stop signals to a handler. No-op without the pcntl extension.
	 *
	 * @param callable $handler Receives the signal number
	 * @return bool Whether handlers were installed
	 */
	public static function register( callable $handler ): bool
	{
		if( !function_exists( 'pcntl_signal' ) )
		{
			return false;
		}

		pcntl_async_signals( true );

		foreach( self::signals() as $signal )
		{
			pcntl_signal( $signal, $handler );
		}

		return true;
	}

	/**
	 * Signal numbers treated as stop requests in this process.
	 *
	 * @return int[]
	 */
	public static function signals(): array
	{
		if( !defined( 'SIGTERM' ) )
		{
			return [];
		}

		$signals = [ SIGTERM, SIGINT ];

		if( self::isHeadless() )
		{
			$signals[] = SIGWINCH;
		}

		return $signals;
	}

	/**
	 * Whether the process runs without a terminal (container, systemd,
	 * supervisor), where SIGWINCH can only be a runtime's stop signal.
	 *
	 * @return bool
	 */
	public static function isHeadless(): bool
	{
		if( self::$_assumeHeadless !== null )
		{
			return self::$_assumeHeadless;
		}

		if( !defined( 'STDIN' ) || !is_resource( STDIN ) )
		{
			return true;
		}

		return !stream_isatty( STDIN );
	}

	/**
	 * Override terminal detection. For tests; null restores detection.
	 *
	 * @param bool|null $headless
	 * @return void
	 */
	public static function assumeHeadless( ?bool $headless ): void
	{
		self::$_assumeHeadless = $headless;
	}
}
