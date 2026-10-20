<?php

namespace Neuron\Jobs\Exceptions;

/**
 * Thrown inside a job when it exceeds the worker's timeout.
 *
 * Raised from the SIGALRM handler, so it surfaces wherever the job happened to
 * be executing and is handled by the normal failure path (retry or failed
 * jobs storage).
 *
 * @package Neuron\Jobs\Exceptions
 */
class JobTimeoutException extends \RuntimeException
{
	/**
	 * @param string $jobClass
	 * @param int $seconds
	 */
	public function __construct( string $jobClass, int $seconds )
	{
		parent::__construct( "Job {$jobClass} exceeded timeout of {$seconds} seconds" );
	}
}
