<?php

namespace Neuron\Jobs\Exceptions;

/**
 * Raised by the runner when a job's run() returns false.
 *
 * IJob documents the return value as the job's success/failure status, so a
 * false return is a failure and goes through the same path as an exception:
 * retry with backoff, then failed-job storage and the failed() hook. Any
 * other return value (including null) is success.
 *
 * @package Neuron\Jobs\Exceptions
 */
class JobReportedFailureException extends \RuntimeException
{
	/**
	 * @param string $jobClass
	 */
	public function __construct( string $jobClass )
	{
		parent::__construct( "Job {$jobClass} reported failure (run() returned false)" );
	}
}
