<?php

namespace Neuron\Jobs\Schedule;

use Cron\CronExpression;

/**
 * One entry from schedule.yaml, as written, with the derived facts a
 * listing needs (is the cron valid, does the class exist, when is it next
 * due). Nothing here instantiates the job.
 *
 * @package Neuron\Jobs\Schedule
 */
final class ScheduleEntry
{
	private ?CronExpression $_expression = null;

	/**
	 * @param string $name Key under `schedule:`
	 * @param string $class Job class as written
	 * @param string $cron Cron expression as written
	 * @param array $args Arguments passed to the job
	 * @param string|null $queue Queue to dispatch to; null runs inline in the scheduler
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $class,
		private readonly string $cron,
		private readonly array $args = [],
		private readonly ?string $queue = null
	)
	{
		if( $this->cron !== '' )
		{
			try
			{
				$this->_expression = new CronExpression( $this->cron );
			}
			catch( \InvalidArgumentException $e )
			{
				$this->_expression = null;
			}
		}
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getClass(): string
	{
		return $this->class;
	}

	public function getCron(): string
	{
		return $this->cron;
	}

	public function getArgs(): array
	{
		return $this->args;
	}

	/**
	 * Queue name, or null for an entry the scheduler runs inline.
	 */
	public function getQueue(): ?string
	{
		return $this->queue;
	}

	/**
	 * Whether the scheduler would dispatch this entry rather than run it.
	 */
	public function isQueued(): bool
	{
		return $this->queue !== null && $this->queue !== '';
	}

	public function isValidCron(): bool
	{
		return $this->_expression !== null;
	}

	public function classExists(): bool
	{
		return $this->class !== '' && class_exists( $this->class );
	}

	/**
	 * Valid cron and an existing class: the scheduler will run it.
	 */
	public function isRunnable(): bool
	{
		return $this->isValidCron() && $this->classExists();
	}

	/**
	 * Next time this entry is due, or null when the cron is invalid.
	 *
	 * @param \DateTimeInterface|null $from Defaults to now
	 * @return \DateTimeImmutable|null
	 */
	public function getNextRun( ?\DateTimeInterface $from = null ): ?\DateTimeImmutable
	{
		if( $this->_expression === null )
		{
			return null;
		}

		try
		{
			return \DateTimeImmutable::createFromInterface( $this->_expression->getNextRunDate( $from ?? 'now' ) );
		}
		catch( \RuntimeException $e )
		{
			return null;
		}
	}

	/**
	 * Previous time this entry was due, or null when the cron is invalid.
	 *
	 * @param \DateTimeInterface|null $from Defaults to now
	 * @return \DateTimeImmutable|null
	 */
	public function getPreviousRun( ?\DateTimeInterface $from = null ): ?\DateTimeImmutable
	{
		if( $this->_expression === null )
		{
			return null;
		}

		try
		{
			return \DateTimeImmutable::createFromInterface( $this->_expression->getPreviousRunDate( $from ?? 'now' ) );
		}
		catch( \RuntimeException $e )
		{
			return null;
		}
	}

	/**
	 * Plain-array form, the shape the CMS Scheduled Jobs view consumes.
	 *
	 * @return array{name:string,class:string,cron:string,args:array,queue:?string,nextRun:?string,valid:bool,classExists:bool}
	 */
	public function toArray(): array
	{
		return [
			'name'        => $this->name,
			'class'       => $this->class,
			'cron'        => $this->cron,
			'args'        => $this->args,
			'queue'       => $this->queue,
			'nextRun'     => $this->getNextRun()?->format( 'Y-m-d H:i:s' ),
			'valid'       => $this->isValidCron(),
			'classExists' => $this->classExists(),
		];
	}
}
