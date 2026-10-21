<?php

namespace Tests\Jobs\Queue\Inspector;

use Neuron\Jobs\Queue\ArrayQueue;
use Neuron\Jobs\Queue\IQueue;

/**
 * IQueueInspector contract against the in-memory ArrayQueue.
 */
class ArrayQueueInspectorTest extends QueueInspectorContractTestCase
{
	protected function createQueue(): IQueue
	{
		return new ArrayQueue( [ 'retry_after' => 90 ] );
	}
}
