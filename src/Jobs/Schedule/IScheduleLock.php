<?php

namespace Neuron\Jobs\Schedule;

/**
 * Guards a scheduled job's trigger so that only one scheduler fires it.
 *
 * Two scheduler processes polling the same schedule would otherwise run or
 * enqueue every due job twice. An implementation claims the pair
 * (job name, scheduled minute) atomically; the first claimant proceeds and
 * the rest skip.
 *
 * @package Neuron\Jobs\Schedule
 */
interface IScheduleLock
{
	/**
	 * Try to claim this job's trigger for the given scheduled time.
	 *
	 * @param string $name Job name from schedule.yaml
	 * @param \DateTimeInterface $scheduled The minute the job is due (not the poll time)
	 * @return bool True if this caller owns the trigger and should fire the job
	 */
	public function acquire( string $name, \DateTimeInterface $scheduled ): bool;
}
