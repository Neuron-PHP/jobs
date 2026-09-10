<?php

namespace Neuron\Jobs\Tests\Migrations;

use PHPUnit\Framework\TestCase;

class CreateQueueTablesStubTest extends TestCase
{
	public function testStubPrimaryKeysAreNotNull(): void
	{
		$stub = dirname( __DIR__, 3 ) . '/resources/migrations/create_queue_tables.php.stub';
		$this->assertFileExists( $stub );

		$contents = (string) file_get_contents( $stub );
		$this->assertStringContainsString(
			"addColumn( 'id', 'string', [ 'limit' => 255, 'null' => false ] )",
			$contents
		);
	}
}
