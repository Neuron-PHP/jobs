<?php

namespace Tests\Jobs\Schedule;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Schedule\ScheduleEntry;
use Neuron\Jobs\Schedule\ScheduleFile;
use PHPUnit\Framework\TestCase;

class ScheduleFileTestJob implements IJob
{
	public function getName(): string
	{
		return 'ScheduleFileTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

class ScheduleFileTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/neuron_schedule_' . uniqid();
		mkdir( $this->dir . '/config', 0755, true );
	}

	protected function tearDown(): void
	{
		foreach( glob( $this->dir . '/config/*' ) ?: [] as $file )
		{
			unlink( $file );
		}

		@rmdir( $this->dir . '/config' );
		@rmdir( $this->dir );

		parent::tearDown();
	}

	private function write( string $yaml, string $name = 'schedule.yaml' ): ScheduleFile
	{
		file_put_contents( $this->dir . '/config/' . $name, $yaml );

		return ScheduleFile::fromBasePath( $this->dir, $name );
	}

	public function testFromBasePathPointsAtConfigDirectory(): void
	{
		$file = ScheduleFile::fromBasePath( $this->dir . '/' );

		$this->assertSame( $this->dir . '/config/schedule.yaml', $file->getPath() );
		$this->assertFalse( $file->exists() );
		$this->assertSame( [], $file->entries() );
	}

	public function testParsesEntriesInFileOrder(): void
	{
		$file = $this->write( <<<YAML
schedule:
  nightly:
    class: Tests\Jobs\Schedule\ScheduleFileTestJob
    cron: "0 2 * * *"
    args:
      days: 30
    queue: maintenance
  hourly:
    class: Tests\Jobs\Schedule\ScheduleFileTestJob
    cron: "0 * * * *"
YAML );

		$this->assertTrue( $file->exists() );

		$entries = $file->entries();
		$this->assertCount( 2, $entries );
		$this->assertContainsOnlyInstancesOf( ScheduleEntry::class, $entries );

		[ $nightly, $hourly ] = $entries;

		$this->assertSame( 'nightly', $nightly->getName() );
		$this->assertSame( ScheduleFileTestJob::class, $nightly->getClass() );
		$this->assertSame( '0 2 * * *', $nightly->getCron() );
		$this->assertSame( [ 'days' => 30 ], $nightly->getArgs() );
		$this->assertSame( 'maintenance', $nightly->getQueue() );
		$this->assertTrue( $nightly->isQueued() );
		$this->assertTrue( $nightly->isValidCron() );
		$this->assertTrue( $nightly->classExists() );
		$this->assertTrue( $nightly->isRunnable() );

		$this->assertSame( 'hourly', $hourly->getName() );
		$this->assertSame( [], $hourly->getArgs() );
		$this->assertNull( $hourly->getQueue() );
		$this->assertFalse( $hourly->isQueued() );
	}

	public function testNextAndPreviousRunFollowTheCron(): void
	{
		$file = $this->write( <<<YAML
schedule:
  hourly:
    class: Tests\Jobs\Schedule\ScheduleFileTestJob
    cron: "0 * * * *"
YAML );

		$from = new \DateTimeImmutable( '2026-01-01 10:30:00' );
		$entry = $file->find( 'hourly' );

		$this->assertNotNull( $entry );
		$this->assertSame( '2026-01-01 11:00:00', $entry->getNextRun( $from )?->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-01-01 10:00:00', $entry->getPreviousRun( $from )?->format( 'Y-m-d H:i:s' ) );
		$this->assertInstanceOf( \DateTimeImmutable::class, $entry->getNextRun() );
	}

	public function testInvalidCronAndMissingClassAreReportedNotThrown(): void
	{
		$file = $this->write( <<<YAML
schedule:
  broken:
    class: App\Jobs\DoesNotExist
    cron: "not a cron"
  blank:
    class: Tests\Jobs\Schedule\ScheduleFileTestJob
YAML );

		$broken = $file->find( 'broken' );
		$this->assertNotNull( $broken );
		$this->assertFalse( $broken->isValidCron() );
		$this->assertFalse( $broken->classExists() );
		$this->assertFalse( $broken->isRunnable() );
		$this->assertNull( $broken->getNextRun() );
		$this->assertNull( $broken->getPreviousRun() );

		$blank = $file->find( 'blank' );
		$this->assertNotNull( $blank );
		$this->assertSame( '', $blank->getCron() );
		$this->assertFalse( $blank->isValidCron() );
		$this->assertTrue( $blank->classExists() );

		$array = $broken->toArray();
		$this->assertFalse( $array['valid'] );
		$this->assertNull( $array['nextRun'] );
		$this->assertSame( 'broken', $array['name'] );
	}

	public function testMalformedEntriesAndFilesAreSkipped(): void
	{
		$file = $this->write( <<<YAML
schedule:
  good:
    class: Tests\Jobs\Schedule\ScheduleFileTestJob
    cron: "* * * * *"
  bad: "just a string"
  weird:
    class: Tests\Jobs\Schedule\ScheduleFileTestJob
    cron: "* * * * *"
    args: "not a map"
    queue: ""
YAML );

		$entries = $file->entries();
		$this->assertSame( [ 'good', 'weird' ], array_map( fn( ScheduleEntry $e ) => $e->getName(), $entries ) );
		$this->assertSame( [], $file->find( 'weird' )->getArgs() );
		$this->assertNull( $file->find( 'weird' )->getQueue() );

		$this->assertSame( [], $this->write( "schedule: 42\n", 'a.yaml' )->entries() );
		$this->assertSame( [], $this->write( "nothing: here\n", 'b.yaml' )->entries() );
		$this->assertSame( [], $this->write( "schedule:\n  x: [unclosed\n", 'c.yaml' )->entries() );
	}

	public function testUpcomingOrdersBySoonestWithInvalidLast(): void
	{
		$file = $this->write( <<<YAML
schedule:
  yearly:
    class: Tests\Jobs\Schedule\ScheduleFileTestJob
    cron: "0 0 1 1 *"
  broken:
    class: Tests\Jobs\Schedule\ScheduleFileTestJob
    cron: "nope"
  minutely:
    class: Tests\Jobs\Schedule\ScheduleFileTestJob
    cron: "* * * * *"
YAML );

		$names = array_map( fn( ScheduleEntry $e ) => $e->getName(), $file->upcoming() );
		$this->assertSame( [ 'minutely', 'yearly', 'broken' ], $names );

		$this->assertCount( 1, $file->upcoming( 1 ) );
		$this->assertSame( 'minutely', $file->upcoming( 1 )[0]->getName() );
	}

	public function testReloadRereadsTheFile(): void
	{
		$file = $this->write( "schedule:\n  a:\n    class: X\n    cron: \"* * * * *\"\n" );
		$this->assertCount( 1, $file->entries() );

		file_put_contents( $file->getPath(), "schedule: {}\n" );
		$this->assertCount( 1, $file->entries(), 'cached' );

		$file->reload();
		$this->assertCount( 0, $file->entries() );
	}
}
