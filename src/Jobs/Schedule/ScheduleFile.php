<?php

namespace Neuron\Jobs\Schedule;

use Neuron\Log\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Read-only view of a schedule.yaml for tooling.
 *
 * The Scheduler parses the same file to run it; this class parses it to
 * show it (CLI listings, admin pages) without instantiating any job class.
 * Malformed files and entries are reported through the log and skipped
 * rather than thrown, so a typo in one entry does not blank a listing.
 *
 *     schedule:
 *       cleanup:
 *         class: App\Jobs\Cleanup
 *         cron: "0 2 * * *"
 *         args: { days: 30 }
 *         queue: maintenance      # optional; omitted runs inline
 *
 * @package Neuron\Jobs\Schedule
 */
final class ScheduleFile
{
	public const DEFAULT_FILENAME = 'schedule.yaml';

	/** @var list<ScheduleEntry>|null */
	private ?array $_entries = null;

	/**
	 * @param string $path Absolute path to the YAML file
	 */
	public function __construct( private readonly string $path )
	{
	}

	/**
	 * The conventional location: `<base>/config/schedule.yaml`.
	 *
	 * @param string $basePath Application base path
	 * @param string $filename
	 * @return self
	 */
	public static function fromBasePath( string $basePath, string $filename = self::DEFAULT_FILENAME ): self
	{
		return new self( rtrim( $basePath, '/' ) . '/config/' . $filename );
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function exists(): bool
	{
		return is_file( $this->path );
	}

	/**
	 * Every well-formed entry, in file order.
	 *
	 * @return list<ScheduleEntry>
	 */
	public function entries(): array
	{
		if( $this->_entries === null )
		{
			$this->_entries = $this->parse();
		}

		return $this->_entries;
	}

	/**
	 * @param string $name
	 * @return ScheduleEntry|null
	 */
	public function find( string $name ): ?ScheduleEntry
	{
		foreach( $this->entries() as $entry )
		{
			if( $entry->getName() === $name )
			{
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Entries ordered by next due time, soonest first; invalid crons last.
	 *
	 * @param int|null $limit
	 * @return list<ScheduleEntry>
	 */
	public function upcoming( ?int $limit = null ): array
	{
		$entries = $this->entries();

		usort( $entries, function( ScheduleEntry $a, ScheduleEntry $b ) {
			$nextA = $a->getNextRun()?->getTimestamp() ?? PHP_INT_MAX;
			$nextB = $b->getNextRun()?->getTimestamp() ?? PHP_INT_MAX;

			return [ $nextA, $a->getName() ] <=> [ $nextB, $b->getName() ];
		} );

		return $limit === null ? $entries : array_slice( $entries, 0, max( 0, $limit ) );
	}

	/**
	 * Forget the cached parse so the next call re-reads the file.
	 */
	public function reload(): void
	{
		$this->_entries = null;
	}

	/**
	 * @return list<ScheduleEntry>
	 */
	private function parse(): array
	{
		if( !$this->exists() )
		{
			return [];
		}

		try
		{
			$data = Yaml::parseFile( $this->path );
		}
		catch( ParseException $e )
		{
			Log::error( "Failed to parse {$this->path}: {$e->getMessage()}" );

			return [];
		}

		$schedule = is_array( $data ) ? ( $data['schedule'] ?? null ) : null;

		if( !is_array( $schedule ) )
		{
			return [];
		}

		$entries = [];

		foreach( $schedule as $name => $job )
		{
			if( !is_array( $job ) )
			{
				Log::warning( "Schedule entry '{$name}' is not a mapping; skipped." );
				continue;
			}

			$args = $job['args'] ?? [];
			$queue = $job['queue'] ?? null;

			$entries[] = new ScheduleEntry(
				(string)$name,
				(string)( $job['class'] ?? '' ),
				(string)( $job['cron'] ?? '' ),
				is_array( $args ) ? $args : [],
				is_string( $queue ) && $queue !== '' ? $queue : null
			);
		}

		return $entries;
	}
}
