<?php

namespace Neuron\Jobs\Queue;

/**
 * The three states a job can be in while it sits in a queue store.
 *
 * Pending   waiting and runnable now:      reserved_at IS NULL, available_at <= now
 * Delayed   waiting for its time:          reserved_at IS NULL, available_at >  now
 * Reserved  held by a worker:              reserved_at IS NOT NULL
 *
 * Failed jobs live in separate storage and are not a JobState.
 *
 * @package Neuron\Jobs\Queue
 */
enum JobState: string
{
	case Pending  = 'pending';
	case Delayed  = 'delayed';
	case Reserved = 'reserved';

	/**
	 * Classify a job as a driver would see it right now.
	 *
	 * @param int|null $reservedAt
	 * @param int $availableAt
	 * @param int|null $now
	 * @return self
	 */
	public static function of( ?int $reservedAt, int $availableAt, ?int $now = null ): self
	{
		$now = $now ?? time();

		if( $reservedAt !== null )
		{
			return self::Reserved;
		}

		return $availableAt > $now ? self::Delayed : self::Pending;
	}

	/**
	 * Parse user input such as a CLI option. Null for anything unrecognised.
	 *
	 * @param string $value
	 * @return self|null
	 */
	public static function tryFromInput( string $value ): ?self
	{
		return self::tryFrom( strtolower( trim( $value ) ) );
	}
}
