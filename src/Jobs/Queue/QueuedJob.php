<?php

namespace Neuron\Jobs\Queue;

use Neuron\Jobs\IJob;

/**
 * Represents a job in the queue with metadata.
 *
 * This class wraps an IJob instance along with queue-specific metadata
 * such as attempts, timestamps, and queue name.
 *
 * @package Neuron\Jobs\Queue
 */
class QueuedJob
{
	private string $_Id;
	private string $_Queue;
	private string $_JobClass;
	private array $_Arguments;
	private int $_Attempts;
	private ?int $_ReservedAt;
	private int $_AvailableAt;
	private int $_CreatedAt;
	private ?string $_RawPayload = null;

	/**
	 * @param string $id Unique job ID
	 * @param string $queue Queue name
	 * @param string $jobClass Fully qualified job class name
	 * @param array $arguments Arguments to pass to the job
	 * @param int $attempts Number of attempts made
	 * @param int|null $reservedAt Timestamp when job was reserved (null if not reserved)
	 * @param int $availableAt Timestamp when job becomes available
	 * @param int $createdAt Timestamp when job was created
	 */
	public function __construct(
		string $id,
		string $queue,
		string $jobClass,
		array $arguments = [],
		int $attempts = 0,
		?int $reservedAt = null,
		int $availableAt = 0,
		int $createdAt = 0
	)
	{
		$this->_Id = $id;
		$this->_Queue = $queue;
		$this->_JobClass = $jobClass;
		$this->_Arguments = $arguments;
		$this->_Attempts = $attempts;
		$this->_ReservedAt = $reservedAt;
		$this->_AvailableAt = $availableAt ?: time();
		$this->_CreatedAt = $createdAt ?: time();
	}

	/**
	 * Create a QueuedJob from an IJob instance
	 *
	 * @param IJob $job Job instance
	 * @param array $arguments Arguments for the job
	 * @param string $queue Queue name
	 * @param int $delay Delay in seconds
	 * @return self
	 */
	public static function fromJob( IJob $job, array $arguments = [], string $queue = 'default', int $delay = 0 ): self
	{
		return new self(
			self::generateId(),
			$queue,
			get_class( $job ),
			$arguments,
			0,
			null,
			time() + $delay,
			time()
		);
	}

	/**
	 * Create a QueuedJob from serialized payload
	 *
	 * @param string $id Job ID
	 * @param string $queue Queue name
	 * @param string $payload Serialized JSON payload
	 * @param int $attempts Number of attempts
	 * @param int|null $reservedAt Reserved timestamp
	 * @param int $availableAt Available timestamp
	 * @param int $createdAt Created timestamp
	 * @return self
	 */
	public static function fromPayload(
		string $id,
		string $queue,
		string $payload,
		int $attempts = 0,
		?int $reservedAt = null,
		int $availableAt = 0,
		int $createdAt = 0
	): self
	{
		$data = json_decode( $payload, true );

		if( !is_array( $data ) || !isset( $data['class'], $data['args'] ) )
		{
			throw new \RuntimeException( 'Invalid job payload' );
		}

		$job = new self(
			$id,
			$queue,
			$data['class'],
			$data['args'],
			$attempts,
			$reservedAt,
			$availableAt,
			$createdAt
		);

		$job->_RawPayload = $payload;

		return $job;
	}

	/**
	 * Get the job instance
	 *
	 * @return IJob
	 * @throws \RuntimeException If job class doesn't exist or doesn't implement IJob
	 */
	public function getJob(): IJob
	{
		if( !class_exists( $this->_JobClass ) )
		{
			throw new \RuntimeException( "Job class not found: {$this->_JobClass}" );
		}

		$job = new $this->_JobClass();

		if( !$job instanceof IJob )
		{
			throw new \RuntimeException( "Job class must implement IJob: {$this->_JobClass}" );
		}

		return $job;
	}

	/**
	 * Get the serialized payload
	 *
	 * @return string JSON encoded payload
	 */
	public function getPayload(): string
	{
		if( $this->_RawPayload !== null )
		{
			return $this->_RawPayload;
		}

		return json_encode([
			'class' => $this->_JobClass,
			'args' => $this->_Arguments
		], JSON_THROW_ON_ERROR );
	}

	/**
	 * Increment the attempts counter
	 *
	 * @return void
	 */
	public function incrementAttempts(): void
	{
		$this->_Attempts++;
	}

	/**
	 * Mark job as reserved
	 *
	 * @return void
	 */
	public function markAsReserved(): void
	{
		$this->_ReservedAt = time();
	}

	/**
	 * Check if job has been reserved
	 *
	 * @return bool
	 */
	public function isReserved(): bool
	{
		return $this->_ReservedAt !== null;
	}

	/**
	 * Check if job is available for processing
	 *
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		return $this->_AvailableAt <= time();
	}

	/**
	 * Generate a unique job ID
	 *
	 * @return string
	 */
	private static function generateId(): string
	{
		return uniqid( 'job_', true );
	}

	// Getters

	public function getId(): string
	{
		return $this->_Id;
	}

	public function getQueue(): string
	{
		return $this->_Queue;
	}

	public function getJobClass(): string
	{
		return $this->_JobClass;
	}

	public function getArguments(): array
	{
		return $this->_Arguments;
	}

	public function getAttempts(): int
	{
		return $this->_Attempts;
	}

	public function getReservedAt(): ?int
	{
		return $this->_ReservedAt;
	}

	public function getAvailableAt(): int
	{
		return $this->_AvailableAt;
	}

	public function getCreatedAt(): int
	{
		return $this->_CreatedAt;
	}

	// Setters

	public function setAvailableAt( int $timestamp ): void
	{
		$this->_AvailableAt = $timestamp;
	}

	public function setReservedAt( ?int $timestamp ): void
	{
		$this->_ReservedAt = $timestamp;
	}
}
