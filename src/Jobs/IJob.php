<?php

namespace Neuron\Jobs;

use Neuron\Patterns\IRunnable;

/**
 * Job interface for the Neuron job scheduling system.
 * 
 * This interface defines the contract for scheduled jobs that can be executed
 * by the Scheduler. Jobs represent discrete units of work that can be scheduled
 * to run at specific times or intervals.
 * 
 * Jobs must implement both the IRunnable interface (providing a run() method)
 * and provide a unique name for identification within the scheduling system.
 * 
 * The job lifecycle:
 * 1. Job is registered with the Scheduler
 * 2. Scheduler determines when to execute based on schedule configuration
 * 3. Scheduler calls run() method to execute the job
 * 4. Job performs its work and returns success/failure status
 * 
 * @package Neuron\Jobs
 * 
 * @example
 * ```php
 * class EmailCleanupJob implements IJob
 * {
 *     public function getName(): string
 *     {
 *         return 'email_cleanup';
 *     }
 *     
 *     public function run(): bool
 *     {
 *         // Clean up old email records
 *         return $this->cleanupOldEmails();
 *     }
 * }
 * ```
 */
interface IJob extends IRunnable
{
	public function getName() : string;
}
