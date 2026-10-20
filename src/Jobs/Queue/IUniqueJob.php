<?php

namespace Neuron\Jobs\Queue;

/**
 * Marks a job that should not be queued twice.
 *
 * Dispatching a unique job while the same job - same class, arguments and
 * queue - is already waiting to run queues nothing and returns the pending
 * job's id. A scheduled job dispatched every few minutes then cannot pile up
 * while its workers are down, to run back to back once they return.
 *
 * Only jobs still waiting count. Once a worker has reserved the job it is
 * running, and a later dispatch is queued as usual.
 *
 * Honoured by drivers implementing IUniqueQueue (database, array). Others
 * queue every dispatch.
 *
 * @package Neuron\Jobs\Queue
 */
interface IUniqueJob
{
}
