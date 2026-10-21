<?php

namespace Tests\Jobs\Queue\Inspector;

use Neuron\Jobs\Queue\FileQueue;
use Neuron\Jobs\Queue\IQueue;

/**
 * IQueueInspector contract against FileQueue in a temporary directory.
 */
class FileQueueInspectorTest extends QueueInspectorContractTestCase
{
	private string $tempPath;

	protected function createQueue(): IQueue
	{
		$this->tempPath = sys_get_temp_dir() . '/neuron_inspector_queue_' . uniqid();

		return new FileQueue( [ 'path' => $this->tempPath, 'retry_after' => 90 ] );
	}

	protected function tearDown(): void
	{
		if( isset( $this->tempPath ) && is_dir( $this->tempPath ) )
		{
			$this->removeDirectory( $this->tempPath );
		}

		parent::tearDown();
	}

	public function testFindJobRejectsPathTraversal(): void
	{
		$this->assertNull( $this->queue->findJob( '../failed/failed_x' ) );
		$this->assertFalse( $this->queue->deleteJob( '../../etc/passwd' ) );
	}

	private function removeDirectory( string $directory ): void
	{
		foreach( array_diff( scandir( $directory ) ?: [], [ '.', '..' ] ) as $item )
		{
			$path = $directory . '/' . $item;

			is_dir( $path ) ? $this->removeDirectory( $path ) : unlink( $path );
		}

		rmdir( $directory );
	}
}
