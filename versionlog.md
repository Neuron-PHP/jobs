## 0.2.28

## 0.2.27 2026-10-23

## 0.2.26 2026-10-23

## 0.2.25 2026-10-21

## 0.2.24 2026-10-21

## 0.2.23 2026-10-20

## 0.2.22 2026-10-20

* **Behaviour change:** a job whose `run()` returns `false` is now treated as
  failed. `IJob` has always documented the return value as the job's
  success/failure status, but the worker ignored it and logged "Job
  completed". A `false` return now goes through the same path as a thrown
  exception (`Exceptions\JobReportedFailureException`): retry with backoff,
  then failed-job storage and the `failed()` hook. The sync driver throws it;
  the scheduler logs an error for a directly-run job. Any other return value,
  including `null`, is success. Jobs returning `false` to mean "nothing to
  do" should return `null` instead.
* `jobs:stats` reports every queue that holds jobs when `--queue` is not
  given, instead of only `default`. A queue nobody is draining was invisible
  unless named explicitly. `IQueueStats::getQueueNames()` provides the list
  (database, file, array and redis drivers); `QueueManager::getQueueNames()`
  and `getDefaultQueue()` expose it.
* A scheduled job that throws no longer takes the scheduler down. Jobs run
  directly in the scheduler process (no `queue:` in `schedule.yaml`) were
  unguarded, so one exception ended the scheduler and every job due after it
  in that poll was skipped. The failure is now logged, the remaining due jobs
  run, and the job is tried again at its next scheduled time. The same guard
  covers a dispatch that throws (queue store unavailable, or the sync driver
  surfacing the job's own exception).

## 0.2.21 2026-10-20

## 0.2.20 2026-10-20

## 0.2.19 2026-10-20

* An idle worker no longer logs "Queue is empty, sleeping for N seconds" on
  every poll.
* `jobs:work` and `jobs:run` now boot the application when `config/` exists
  but holds no `neuron.yaml`, as `jobs:schedule` already did. A container
  configured entirely through environment variables was logging "no
  configuration directory could be found" and running every job without the
  application's initializers. A directory holding `neuron.yaml` is still
  preferred when several candidates exist.
* The worker's and scheduler's shutdown log line names the signal that asked
  (`SIGTERM`, `SIGINT`, `SIGWINCH`), so a container that keeps stopping can be
  traced to whoever is sending it. `Worker::stop()` and `Scheduler::stop()`
  take the signal number as an optional argument; `StopSignals::name()`
  formats it.
* `jobs:schedule` loads settings through `SettingManagerFactory` rather than
  reading `neuron.yaml` directly, so environment-specific files, encrypted
  secrets and environment variables apply when the Registry has no settings
  yet.

## 0.2.18 2026-10-20

* Workers, the scheduler and `jobs:run` now treat `SIGWINCH` as a stop
  request when no terminal is attached (`Process\StopSignals`). The official
  `php:*-apache` Docker image declares `STOPSIGNAL SIGWINCH`, so `docker stop`,
  Kubernetes, ECS and Railway all sent that to CLI containers built from it;
  PHP ignored it and the process was killed mid-job when the grace period
  ended. In a terminal `SIGWINCH` still just means the window was resized.

## 0.2.17 2026-10-20

Workers and the scheduler now run as proper long-lived application processes.

* Added worker bootstrap: `queue.bootstrap` names an `IWorkerBootstrap` whose
  `boot()` returns the application; `jobs:work` and `jobs:schedule` boot it
  once, `start()` it, and `finish()` it on exit, so jobs get the container,
  database connection, event listeners and view paths a web request has. Job
  classes are resolved through the container. `--config` / `-c` locates the
  configuration directory; `--no-bootstrap` skips it. Log lines carry a `role`
  context (`scheduler` / `worker`).
* Added `QueueManager::fromRegistry()`; fixed `dispatch()` / `getQueueManager()`
  and the CLI commands reading the manager under a different registry key.
* Added job timeouts enforced with `pcntl_alarm` (`JobTimeoutException`), and
  worker `--memory` and `--max-time` limits for self-recycling under
  systemd / supervisor.
* Added `IQueueable` for per-job queue, max attempts, backoff, timeout and a
  `failed()` hook.
* Added the `array` driver (`ArrayQueue`) and the `Testing\QueueAssertions`
  trait (`assertDispatched()` and friends) for tests.
* Added the `redis` driver (`RedisQueue`): ready list, delayed and reserved
  sorted sets, atomic Lua pop. Needs the phpredis extension, checked at
  runtime; deliberately not a Composer requirement. Configure under
  `queue.redis` (`url` or host / port / password / database, `prefix`).
* Added `IQueueStats`; `jobs:stats` now reports delayed jobs, jobs in progress
  and the age of the oldest pending job per queue.
* Added `jobs:restart`: touches `queue.restart_file` (default
  `storage/jobs.restart`); workers exit between jobs once it is newer than
  their start time.
* Added an optional schedule lock (`queue.schedule_lock: database`,
  `DatabaseScheduleLock`) so several `jobs:schedule` instances can share one
  schedule without triggering jobs twice.
* `jobs:run` restarts a child that exits: immediately after a clean exit,
  with exponential backoff after a crash (`--max-restarts`,
  `--max-restart-delay`, `--no-restart`). It was also never registered with
  the CLI; it is now.
* `DatabaseQueue` claims jobs with `FOR UPDATE SKIP LOCKED` on PostgreSQL,
  MySQL 8+ and MariaDB 10.6+; SQLite keeps a bounded compare-and-set loop.
  `retry_after` is now passed through to the driver, and the database
  connection comes from `Neuron\Data\Database\ConnectionFactory` (so
  `database.url` and `DATABASE_URL` work for the queue too).
* `FileQueue` honours reservations: a reserved job is no longer handed to a
  second worker, stale reservations are reaped after `retry_after`, and
  `size()` excludes reserved jobs.
* The scheduler handles `SIGTERM` / `SIGINT`: the poll in progress finishes,
  then the loop exits instead of sleeping, so `docker stop` and
  `systemctl stop` are lossless.
* `QueueManager` warns when falling back to the `sync` driver because no
  `queue:` section exists.

## 0.2.16 2026-10-19

## 0.2.15 2026-09-10

## 0.2.14 2026-06-05

## 0.2.13 2026-01-13

## 0.2.12 2025-11-28

## 0.2.11 2025-11-27
* Added job events.

## 0.2.10 2025-11-24

## 0.2.9 2025-11-22
* Adds scaffolding commands.

## 0.2.8 2025-11-12
* Adds the jobs:run command to execute both the scheduler and worker in a single process.

## 0.2.7 2025-11-12

## 0.2.6 2025-11-12
* Renamed config.yaml to neuron.yaml

## 0.2.5 2025-11-11

## 0.2.4 2025-11-10
* Scheduled jobs can now be queued for asynchronous processing.

## 0.2.3 2025-11-08

* Added asynchronous job queue system.

## 0.2.1 2025-11-08
* Composer update.

## 0.2.0 2025-08-13
* Added CLI commands.

## 0.1.5 2025-01-16
* Updated bootstrap to support the new core.

## 0.1.4 2025-01-15
* Completed tests.
* Missing config values now automatically fallback to env values.
* Config file name can now be modified.

## 0.1.3 2025-11-08
## 0.1.2 2025-01-10
* Updates for core refactoring.

## 0.1.1 2025-01-09
* Updates for core refactoring. 

## 0.1.0 2025-01-07
* Initial release
* Loads jobs from schedule.yaml
* Runs as an infinite polling loop.
* Runs as a single polling instance.
