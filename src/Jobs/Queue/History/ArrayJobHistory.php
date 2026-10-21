<?php

namespace Neuron\Jobs\Queue\History;

/**
 * In-memory run history for tests and the array queue driver.
 *
 * @package Neuron\Jobs\Queue\History
 */
class ArrayJobHistory implements IJobHistory
{
	/** @var array<string,array> */
	private array $_runs = [];
	private int $_retention;

	public function __construct( int $retention = 7 * 86400 )
	{
		$this->_retention = max( 60, $retention );
	}

	public function record( array $run ): void
	{
		$id = (string)( $run['id'] ?? uniqid( 'run_', true ) );
		$run['id'] = $id;
		$this->_runs[ $id ] = $run;
	}

	public function listRuns( array $filters = [], int $limit = 50, int $offset = 0 ): array
	{
		$matches = $this->matching( $filters );
		usort( $matches, fn( array $a, array $b ) => [ $b['finished_at'] ?? 0, $b['id'] ] <=> [ $a['finished_at'] ?? 0, $a['id'] ] );

		return array_slice( $matches, max( 0, $offset ), max( 1, $limit ) );
	}

	public function countRuns( array $filters = [] ): int
	{
		return count( $this->matching( $filters ) );
	}

	public function findRun( string $id ): ?array
	{
		return $this->_runs[ $id ] ?? null;
	}

	public function durationPercentiles( array $filters = [] ): array
	{
		$filters['status'] = self::STATUS_COMPLETED;
		$durations = array_values( array_map(
			fn( array $run ) => (int)( $run['duration_ms'] ?? 0 ),
			$this->matching( $filters )
		) );
		sort( $durations );

		return [
			'p50' => self::percentile( $durations, 50 ),
			'p95' => self::percentile( $durations, 95 ),
			'count' => count( $durations ),
		];
	}

	public function countsByStatus( array $filters = [] ): array
	{
		$completed = 0;
		$failed = 0;

		foreach( $this->matching( $filters ) as $run )
		{
			if( ( $run['status'] ?? '' ) === self::STATUS_COMPLETED )
			{
				$completed++;
			}
			elseif( ( $run['status'] ?? '' ) === self::STATUS_FAILED )
			{
				$failed++;
			}
		}

		return [ 'completed' => $completed, 'failed' => $failed ];
	}

	public function classes( array $filters = [] ): array
	{
		$classes = [];

		foreach( $this->matching( $filters ) as $run )
		{
			$class = (string)( $run['job_class'] ?? '' );

			if( $class !== '' )
			{
				$classes[ $class ] = true;
			}
		}

		$classes = array_keys( $classes );
		sort( $classes );

		return $classes;
	}

	public function queues( array $filters = [] ): array
	{
		$queues = [];

		foreach( $this->matching( $filters ) as $run )
		{
			$queue = (string)( $run['queue'] ?? '' );

			if( $queue !== '' )
			{
				$queues[ $queue ] = true;
			}
		}

		$queues = array_keys( $queues );
		sort( $queues );

		return $queues;
	}

	public function prune(): int
	{
		$cutoff = time() - $this->_retention;
		$removed = 0;

		foreach( $this->_runs as $id => $run )
		{
			if( (int)( $run['finished_at'] ?? 0 ) < $cutoff )
			{
				unset( $this->_runs[ $id ] );
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * @return list<array>
	 */
	private function matching( array $filters ): array
	{
		$matches = [];

		foreach( $this->_runs as $run )
		{
			if( self::matches( $run, $filters ) )
			{
				$matches[] = $run;
			}
		}

		return $matches;
	}

	private static function matches( array $run, array $filters ): bool
	{
		if( isset( $filters['queue'] ) && $filters['queue'] !== null && $filters['queue'] !== '' && ( $run['queue'] ?? '' ) !== $filters['queue'] )
		{
			return false;
		}

		if( isset( $filters['class'] ) && $filters['class'] !== null && $filters['class'] !== '' && ( $run['job_class'] ?? '' ) !== $filters['class'] )
		{
			return false;
		}

		if( isset( $filters['status'] ) && $filters['status'] !== null && $filters['status'] !== '' && ( $run['status'] ?? '' ) !== $filters['status'] )
		{
			return false;
		}

		if( isset( $filters['since'] ) && $filters['since'] !== null && (int)( $run['finished_at'] ?? 0 ) < (int)$filters['since'] )
		{
			return false;
		}

		if( isset( $filters['until'] ) && $filters['until'] !== null && (int)( $run['finished_at'] ?? 0 ) > (int)$filters['until'] )
		{
			return false;
		}

		return true;
	}

	/**
	 * Nearest-rank percentile. Null when there are no samples.
	 *
	 * @param list<int> $sorted
	 */
	public static function percentile( array $sorted, int $percent ): ?int
	{
		if( $sorted === [] )
		{
			return null;
		}

		$index = (int)ceil( count( $sorted ) * $percent / 100 ) - 1;

		return $sorted[ max( 0, min( $index, count( $sorted ) - 1 ) ) ];
	}
}
