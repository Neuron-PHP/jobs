<?php

namespace Neuron\Jobs\Bootstrap;

use Neuron\Application\Base;

/**
 * Builds the application a worker or scheduler process runs inside.
 *
 * A queue worker is a long-lived process with no HTTP request, but the jobs it
 * executes expect the same environment a controller gets: settings, the DI
 * container, the ORM connection, event listeners, view paths. Implementations
 * construct that application the same way the site's `public/index.php` does;
 * the worker then runs its lifecycle via Base::start() / Base::finish().
 *
 * Configure with `queue.bootstrap` in neuron.yaml:
 *
 * ```yaml
 * queue:
 *   bootstrap: Neuron\Cms\Jobs\WorkerBootstrap
 * ```
 *
 * @package Neuron\Jobs\Bootstrap
 */
interface IWorkerBootstrap
{
	/**
	 * Construct (but do not start) the application for this process.
	 *
	 * @param string $configPath Absolute path to the configuration directory
	 * @return Base|null Null when no application can be built; the worker then
	 *         runs without application context.
	 */
	public function boot( string $configPath ): ?Base;
}
