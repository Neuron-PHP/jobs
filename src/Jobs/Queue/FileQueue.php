<?php

namespace Neuron\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

/**
 * File-based queue implementation.
 *
 * Stores jobs in JSON files on the filesystem.
 * Simple and requires no database, but not suitable for high-throughput applications.
 *
 * @package Neuron\Jobs\Queue
 */
class FileQueue implements IQueue
{
	private string $_Path;
	private string $_FailedPath;

	/**
	 * @param array $config Configuration array
	 */
	public function __construct( array $config = [] )
	{
		$this->_Path = rtrim( $config['path'] ?? 'storage/queue', '/' );
		$this->_FailedPath = $this->_Path . '/failed';

		$this->ensureDirectoryExists( $this->_Path );
		$this->ensureDirectoryExists( $this->_FailedPath );
	}

	/**
	 * Ensure directory exists
	 *
	 * @param string $path
	 * @return void
	 */
	private function ensureDirectoryExists( string $path ): void
	{
		if( !is_dir( $path ) )
		{
			if( !mkdir( $path, 0755, true ) )
			{
				throw new \RuntimeException( "Failed to create queue directory: {$path}" );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function push( IJob $job, array $args = [], string $queue = 'default', int $delay = 0 ): string
	{
		$queuedJob = QueuedJob::fromJob( $job, $args, $queue, $delay );

		$queuePath = $this->getQueuePath( $queue );
		$this->ensureDirectoryExists( $queuePath );

		$filename = $this->getJobFilename( $queuedJob );
		$data = $this->serializeJob( $queuedJob );

		if( file_put_contents( $filename, $data, LOCK_EX ) === false )
		{
			throw new \RuntimeException( "Failed to write job to queue: {$filename}" );
		}

		Log::debug( "Job pushed to file queue: {$queuedJob->getId()} ({$queuedJob->getJobClass()})" );

		return $queuedJob->getId();
	}

	/**
	 * @inheritDoc
	 */
	public function pop( string $queue = 'default' ): ?QueuedJob
	{
		$queuePath = $this->getQueuePath( $queue );

		if( !is_dir( $queuePath ) )
		{
			return null;
		}

		// Get all job files sorted by available_at timestamp
		$files = glob( $queuePath . '/job_*.json' );

		if( empty( $files ) )
		{
			return null;
		}

		$now = time();

		// Sort files by modification time (oldest first)
		usort( $files, fn( $a, $b ) => filemtime( $a ) <=> filemtime( $b ) );

		foreach( $files as $file )
		{
			// Try to get exclusive lock
			$fp = fopen( $file, 'r+' );

			if( !$fp || !flock( $fp, LOCK_EX | LOCK_NB ) )
			{
				if( $fp )
				{
					fclose( $fp );
				}
				continue; // File is locked by another worker
			}

			try
			{
				$data = fread( $fp, filesize( $file ) );

				if( $data === false )
				{
					flock( $fp, LOCK_UN );
					fclose( $fp );
					continue;
				}

				$jobData = json_decode( $data, true );

				if( !is_array( $jobData ) )
				{
					flock( $fp, LOCK_UN );
					fclose( $fp );
					continue;
				}

				// Check if job is available
				if( $jobData['available_at'] > $now )
				{
					flock( $fp, LOCK_UN );
					fclose( $fp );
					continue;
				}

				// Update attempts and reserved_at
				$jobData['attempts']++;
				$jobData['reserved_at'] = $now;

				// Write updated data
				ftruncate( $fp, 0 );
				rewind( $fp );
				fwrite( $fp, json_encode( $jobData, JSON_PRETTY_PRINT ) );

				flock( $fp, LOCK_UN );
				fclose( $fp );

				// Create QueuedJob
				$job = QueuedJob::fromPayload(
					$jobData['id'],
					$jobData['queue'],
					$jobData['payload'],
					$jobData['attempts'],
					$jobData['reserved_at'],
					$jobData['available_at'],
					$jobData['created_at']
				);

				Log::debug( "Job popped from file queue: {$job->getId()}" );

				return $job;
			}
			catch( \Throwable $e )
			{
				flock( $fp, LOCK_UN );
				fclose( $fp );
				Log::error( "Error reading job file: {$file} - {$e->getMessage()}" );
			}
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function release( QueuedJob $job, int $delay = 0 ): void
	{
		$filename = $this->getJobFilenameById( $job->getId(), $job->getQueue() );

		if( !file_exists( $filename ) )
		{
			return;
		}

		$fp = fopen( $filename, 'r+' );

		if( !$fp || !flock( $fp, LOCK_EX ) )
		{
			if( $fp )
			{
				fclose( $fp );
			}
			return;
		}

		$data = fread( $fp, filesize( $filename ) );
		$jobData = json_decode( $data, true );

		if( is_array( $jobData ) )
		{
			$jobData['reserved_at'] = null;
			$jobData['available_at'] = time() + $delay;

			ftruncate( $fp, 0 );
			rewind( $fp );
			fwrite( $fp, json_encode( $jobData, JSON_PRETTY_PRINT ) );
		}

		flock( $fp, LOCK_UN );
		fclose( $fp );

		Log::debug( "Job released to file queue: {$job->getId()}" );
	}

	/**
	 * @inheritDoc
	 */
	public function delete( QueuedJob $job ): void
	{
		$filename = $this->getJobFilenameById( $job->getId(), $job->getQueue() );

		if( file_exists( $filename ) )
		{
			unlink( $filename );
			Log::debug( "Job deleted from file queue: {$job->getId()}" );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function failed( QueuedJob $job, \Throwable $exception ): void
	{
		$filename = $this->_FailedPath . '/failed_' . $job->getId() . '.json';

		$data = [
			'id' => $job->getId(),
			'queue' => $job->getQueue(),
			'payload' => $job->getPayload(),
			'exception' => sprintf(
				"%s: %s\n%s",
				get_class( $exception ),
				$exception->getMessage(),
				$exception->getTraceAsString()
			),
			'failed_at' => time()
		];

		file_put_contents( $filename, json_encode( $data, JSON_PRETTY_PRINT ), LOCK_EX );

		$this->delete( $job );

		Log::error( "Job failed: {$job->getId()} - {$exception->getMessage()}" );
	}

	/**
	 * @inheritDoc
	 */
	public function size( string $queue = 'default' ): int
	{
		$queuePath = $this->getQueuePath( $queue );

		if( !is_dir( $queuePath ) )
		{
			return 0;
		}

		$files = glob( $queuePath . '/job_*.json' );

		return count( $files );
	}

	/**
	 * @inheritDoc
	 */
	public function clear( string $queue = 'default' ): int
	{
		$queuePath = $this->getQueuePath( $queue );

		if( !is_dir( $queuePath ) )
		{
			return 0;
		}

		$files = glob( $queuePath . '/job_*.json' );
		$count = 0;

		foreach( $files as $file )
		{
			if( unlink( $file ) )
			{
				$count++;
			}
		}

		Log::info( "Cleared {$count} jobs from file queue: {$queue}" );

		return $count;
	}

	/**
	 * @inheritDoc
	 */
	public function getFailedJobs(): array
	{
		$files = glob( $this->_FailedPath . '/failed_*.json' );
		$jobs = [];

		foreach( $files as $file )
		{
			$data = file_get_contents( $file );
			$jobData = json_decode( $data, true );

			if( is_array( $jobData ) )
			{
				$jobs[] = $jobData;
			}
		}

		// Sort by failed_at descending
		usort( $jobs, fn( $a, $b ) => $b['failed_at'] <=> $a['failed_at'] );

		return $jobs;
	}

	/**
	 * @inheritDoc
	 */
	public function retryFailedJob( string $id ): bool
	{
		$filename = $this->_FailedPath . '/failed_' . $id . '.json';

		if( !file_exists( $filename ) )
		{
			return false;
		}

		$data = file_get_contents( $filename );
		$jobData = json_decode( $data, true );

		if( !is_array( $jobData ) )
		{
			return false;
		}

		// Recreate job in queue
		$queuePath = $this->getQueuePath( $jobData['queue'] );
		$this->ensureDirectoryExists( $queuePath );

		$newId = uniqid( 'job_', true );
		$newFilename = $queuePath . '/' . $newId . '.json';

		$newJobData = [
			'id' => $newId,
			'queue' => $jobData['queue'],
			'payload' => $jobData['payload'],
			'attempts' => 0,
			'reserved_at' => null,
			'available_at' => time(),
			'created_at' => time()
		];

		file_put_contents( $newFilename, json_encode( $newJobData, JSON_PRETTY_PRINT ), LOCK_EX );

		// Delete failed job file
		unlink( $filename );

		Log::info( "Failed job retried: {$id}" );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function forgetFailedJob( string $id ): bool
	{
		$filename = $this->_FailedPath . '/failed_' . $id . '.json';

		if( file_exists( $filename ) )
		{
			unlink( $filename );
			Log::info( "Failed job deleted: {$id}" );
			return true;
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function clearFailedJobs(): int
	{
		$files = glob( $this->_FailedPath . '/failed_*.json' );
		$count = 0;

		foreach( $files as $file )
		{
			if( unlink( $file ) )
			{
				$count++;
			}
		}

		Log::info( "Cleared {$count} failed jobs" );

		return $count;
	}

	/**
	 * Get queue directory path
	 *
	 * @param string $queue
	 * @return string
	 */
	private function getQueuePath( string $queue ): string
	{
		return $this->_Path . '/' . $queue;
	}

	/**
	 * Get job filename
	 *
	 * @param QueuedJob $job
	 * @return string
	 */
	private function getJobFilename( QueuedJob $job ): string
	{
		return $this->getQueuePath( $job->getQueue() ) . '/' . $job->getId() . '.json';
	}

	/**
	 * Get job filename by ID
	 *
	 * @param string $id
	 * @param string $queue
	 * @return string
	 */
	private function getJobFilenameById( string $id, string $queue ): string
	{
		return $this->getQueuePath( $queue ) . '/' . $id . '.json';
	}

	/**
	 * Serialize job to JSON
	 *
	 * @param QueuedJob $job
	 * @return string
	 */
	private function serializeJob( QueuedJob $job ): string
	{
		$data = [
			'id' => $job->getId(),
			'queue' => $job->getQueue(),
			'payload' => $job->getPayload(),
			'attempts' => $job->getAttempts(),
			'reserved_at' => $job->getReservedAt(),
			'available_at' => $job->getAvailableAt(),
			'created_at' => $job->getCreatedAt()
		];

		return json_encode( $data, JSON_PRETTY_PRINT );
	}
}
