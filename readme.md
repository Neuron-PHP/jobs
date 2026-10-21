[![CI](https://github.com/Neuron-PHP/jobs/actions/workflows/ci.yml/badge.svg)](https://github.com/Neuron-PHP/jobs/actions)
[![codecov](https://codecov.io/gh/Neuron-PHP/jobs/branch/develop/graph/badge.svg)](https://codecov.io/gh/Neuron-PHP/jobs)
# Neuron-PHP Job Scheduler & Queue

A lightweight job scheduler and queue system for PHP 8.4+. Schedule recurring tasks with cron expressions and process background jobs with a reliable queue system.

## Features

- **Combined Runner**: Single command to run scheduler and worker together
- **Job Scheduling**: Schedule recurring jobs with cron expressions
- **Queue System**: Background job processing with retry logic
- **Multiple Drivers**: Database (`SKIP LOCKED` where supported), Redis, file, in-memory (tests), or synchronous execution
- **Application Context in Workers**: Workers boot your application so jobs get the container, database and events
- **Operations**: `jobs:restart` after deploys, `jobs:stats` with delayed/in-progress/age, optional schedule lock for several schedulers
- **Failed Job Management**: Track, retry, and monitor failed jobs
- **Per-Job Settings**: Optional `IQueueable` for queue, retries, backoff, timeout and a `failed()` hook
- **Test Helpers**: `QueueAssertions` trait with `assertDispatched()` and friends
- **Helper Functions**: Simple API for dispatching jobs

## Quick Start

```bash
# Install
composer require neuron-php/jobs

# Run the complete job system
vendor/bin/neuron jobs:run
```

That's it! The `jobs:run` command handles both scheduled tasks and queued jobs.

## Installation

```bash
composer require neuron-php/jobs
```

Configure PSR-4 autoloading in composer.json:

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

## Directory Structure

```
├── composer.json
├── config
│   ├── neuron.yaml      # Application configuration
│   └── schedule.yaml    # Job schedule configuration
├── src
│   └── Jobs
│       ├── MyScheduledJob.php
│       └── MyQueuedJob.php
└── vendor
```

## Configuration

### Application Configuration (neuron.yaml)

```yaml
# Basic configuration
system:
  timezone: America/New_York
  base_path: /path/to/app

logging:
  destination: \Neuron\Log\Destination\File
  format: \Neuron\Log\Format\PlainText
  file: storage/logs/app.log
  level: debug

# Queue configuration
queue:
  driver: database           # database, redis, file, array, or sync
  default: default           # Default queue name
  retry_after: 90           # Seconds a worker may hold a job before it is released to another
  max_attempts: 3           # Maximum retry attempts
  backoff: 10               # Base seconds between retries (doubles each attempt)
  bootstrap: App\Jobs\WorkerBootstrap   # Boots the application inside jobs:work / jobs:schedule
  schedule_lock: database   # Optional: lets several jobs:schedule instances share one schedule
  restart_file: storage/jobs.restart    # Optional: touched by jobs:restart; default shown
  # redis:                  # Only for driver: redis
  #   url: redis://127.0.0.1:6379/0     # or host / port / password / database
  #   prefix: neuron:queue

# Database configuration (for database driver; same section the application uses)
database:
  adapter: sqlite
  name: storage/database.sqlite
  # For MySQL/PostgreSQL:
  # adapter: mysql
  # host: localhost
  # port: 3306
  # user: username
  # pass: password
  # charset: utf8mb4
  # Or a single URL:
  # url: mysql://username:password@localhost:3306/dbname?charset=utf8mb4
```

**Queue Drivers:**

- **database**: Persistent queue in database (recommended for production). On
  PostgreSQL, MySQL 8+ and MariaDB 10.6+ workers claim jobs with
  `FOR UPDATE SKIP LOCKED`; SQLite and older servers use a compare-and-set
  update, which is correct but contends under many workers.
- **redis**: List/sorted-set queue with an atomic Lua pop. Needs the `redis`
  PHP extension (phpredis), which is checked at runtime and is deliberately
  not a Composer requirement. Configure under `queue.redis` with either `url`
  or `host`/`port`/`password`/`database`, plus an optional key `prefix`.
  Durability is Redis's own: with the default RDB snapshots a crash can lose
  the most recent pushes, so enable AOF (`appendonly yes`) on the queue's
  Redis when that matters.
- **file**: File-based queue in storage directory
- **array**: In-memory queue for tests (see `Neuron\Jobs\Testing\QueueAssertions`)
- **sync**: Execute jobs immediately in the dispatching process. This is the
  fallback when no `queue:` section exists; a warning is logged.

**Worker bootstrap (`queue.bootstrap`):**

Name a class implementing `Neuron\Jobs\Bootstrap\IWorkerBootstrap` whose
`boot( string $configPath ): ?Base` returns your application (for example by
calling your `boot()` function). `jobs:work` and `jobs:schedule` call it once
at startup, then `start()` the application, so jobs run with the same
container, database connection, event listeners and view paths a web request
has, and job classes are resolved through the container. Without it workers
load event listeners only. CMS sites use `Neuron\Cms\Jobs\WorkerBootstrap`.

## Job Scheduling

### Schedule Configuration (config/schedule.yaml)

```yaml
schedule:
  # Quick job - runs directly in scheduler
  cleanup_logs:
    class: App\Jobs\CleanupLogsJob
    cron: "0 2 * * *"              # Daily at 2:00 AM
    args:
      max_age_days: 30

  # Long-running job - dispatch to queue
  process_reports:
    class: App\Jobs\GenerateReportsJob
    cron: "0 8 * * 1"              # Monday at 8:00 AM
    queue: reports                 # Dispatch to 'reports' queue
    args:
      report_type: weekly

  # High-priority job with specific queue
  send_notifications:
    class: App\Jobs\SendNotificationsJob
    cron: "*/15 * * * *"           # Every 15 minutes
    queue: high-priority
```

**Configuration Options:**

- `class`: Job class name (required)
- `cron`: Cron expression for schedule (required)
- `args`: Arguments passed to job (optional)
- `queue`: Queue name for background processing (optional)

**When to Use Queue Dispatch:**

- **Without queue**: Quick jobs (< 1 second) that won't block scheduler
- **With queue**: Long-running jobs requiring retries and monitoring

### Creating Scheduled Jobs

Jobs implement the `Neuron\Jobs\IJob` interface:

```php
namespace App\Jobs;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

class CleanupLogsJob implements IJob
{
    public function getName(): string
    {
        return 'cleanup-logs';
    }

    public function run(array $args = []): mixed
    {
        $maxAgeDays = $args['max_age_days'] ?? 30;

        Log::info("Cleaning up logs older than {$maxAgeDays} days");

        // Your cleanup logic here
        $deletedCount = 0;

        return $deletedCount;
    }
}
```

## Running Jobs

The job system can be run in three different modes depending on your needs:

### 1. Combined Mode (Development)

Run both scheduler and queue worker together with a single command:

```bash
vendor/bin/neuron jobs:run
```

This is the **easiest way** to run the complete job system locally. It manages
both the scheduler and worker in one process. In production run the scheduler
(one instance, or several with `queue.schedule_lock`) and workers (as many as
you need) as separate services so they restart and scale independently.

**Options:**
- `--schedule-interval=30` - Scheduler polling interval in seconds (default: 60)
- `--queue=emails,default` - Queue(s) to process (default: default)
- `--worker-sleep=5` - Worker sleep when queue is empty (default: 3)
- `--worker-timeout=120` - Job timeout in seconds (default: 60)
- `--max-jobs=100` - Max jobs before restarting worker (default: unlimited)
- `--max-restarts=N` - Give up on a child after N consecutive crashes (default: 0, never)
- `--max-restart-delay=60` - Cap on the restart backoff in seconds (default: 60)
- `--no-restart` - Do not restart children that exit

A child that exits cleanly (for example a worker past `--max-jobs`) is
restarted at once. A crashing child is restarted after 1, 2, 4, 8… seconds up
to `--max-restart-delay`; the counter resets once it has stayed up a minute.

**Examples:**
```bash
# Run with defaults (both scheduler and worker)
vendor/bin/neuron jobs:run

# Custom configuration
vendor/bin/neuron jobs:run --schedule-interval=30 --queue=emails,notifications
```

### 2. Scheduler Only

Run just the scheduler for executing scheduled tasks:

**Daemon Mode** (continuous polling):

```bash
# Poll every 60 seconds (default)
vendor/bin/neuron jobs:schedule

# Custom polling interval
vendor/bin/neuron jobs:schedule --interval=30
```

The daemon handles `SIGTERM`/`SIGINT` (with the pcntl extension): a poll in
progress finishes, so every job due at that minute is still triggered, then
the process exits instead of sleeping until the next interval. That makes
`systemctl stop`, `docker stop` and `jobs:run` shutdowns lossless.

**Stop signals in containers:** when no terminal is attached, the scheduler,
workers and `jobs:run` also stop on `SIGWINCH`. The official `php:*-apache`
Docker image declares `STOPSIGNAL SIGWINCH` (Apache's graceful stop), so
that is what `docker stop`, Kubernetes, ECS and Railway send a CLI container
built from it; without this the process would be killed mid-job when the
grace period ran out. Setting `stop_signal: SIGTERM` on those services is
still good practice but no longer required.

**Cron Mode** (single poll per invocation):

Add to your system crontab:

```cron
* * * * * cd /path/to/app && vendor/bin/neuron jobs:schedule --poll
```

**Several schedulers:** run one scheduler per deployment unless you set
`queue.schedule_lock: database`. With the lock on, each scheduler inserts a
`(job name, due minute)` row into `schedule_locks` before triggering; only the
first insert wins, so a job due at 02:00 runs once however many schedulers
poll. The table is created on first use (database driver only; it shares the
queue connection). Rows older than a week are pruned automatically.

### 3. Worker Only

Run just the queue worker for processing background jobs:

```bash
# Process default queue
vendor/bin/neuron jobs:work

# Process specific queue
vendor/bin/neuron jobs:work --queue=emails

# Process multiple queues (priority order)
vendor/bin/neuron jobs:work --queue=high,default,low

# Custom options
vendor/bin/neuron jobs:work --sleep=5 --timeout=120 --max-jobs=100

# Recycle the process (under systemd/supervisor) past 128MB or after an hour
vendor/bin/neuron jobs:work --memory=128 --max-time=3600
```

**Worker Options:**
- `--queue, -Q`: Queue(s) to process (comma-separated), default: `default`
- `--once`: Process one job then exit
- `--stop-when-empty`: Stop when no jobs available
- `--sleep, -s`: Seconds to sleep when queue empty, default: `3`
- `--max-jobs, -m`: Maximum jobs before stopping, default: `0` (unlimited)
- `--timeout, -t`: Job timeout in seconds, default: `60`, `0` disables. Enforced
  with `pcntl_alarm`; without the pcntl extension the worker warns once and
  jobs run unbounded. A timed-out job fails with `JobTimeoutException` and is
  retried like any other failure.
- `--memory`: Exit once memory use exceeds this many MB (checked between jobs), default: `0` (no limit)
- `--max-time`: Exit after running this many seconds (checked between jobs), default: `0` (no limit)
- `--config, -c`: Configuration directory; found automatically from the working directory when omitted
- `--no-bootstrap`: Skip `queue.bootstrap`; jobs run without the application context

Log lines written by the scheduler and workers carry a `role` context value
(`scheduler` / `worker`) so the processes can be told apart in a shared log.

**Restarting workers after a deploy:**

Workers hold the code they were started with. Instead of hunting down
processes, touch the restart file:

```bash
vendor/bin/neuron jobs:restart
```

Every worker checks the file's mtime between jobs and exits cleanly once it
is newer than the worker's start time; systemd/supervisor then starts a fresh
process on the new code. The file defaults to `storage/jobs.restart` under
the application root and can be moved with `queue.restart_file`.

## Queue Processing

### Dispatching Jobs to Queue

#### Using Helper Functions

```php
use App\Jobs\SendEmailJob;

// Basic dispatch
dispatch(new SendEmailJob(), [
    'to' => 'user@example.com',
    'subject' => 'Welcome',
    'body' => 'Welcome to our site!'
]);

// Specific queue
dispatch(new ProcessImageJob(), ['path' => '/tmp/image.jpg'], 'images');

// Delayed job (execute in 1 hour)
dispatch(new SendReminderJob(), ['order_id' => 123], 'default', 3600);

// Execute immediately (bypass queue)
$result = dispatchNow(new ProcessDataJob(), ['data' => $data]);
```

#### Using QueueManager Directly

```php
use Neuron\Jobs\Queue\QueueManager;

// The shared instance every process (web, scheduler, worker) resolves
$queueManager = QueueManager::fromRegistry();

// Dispatch to queue
$jobId = $queueManager->dispatch(
    new SendEmailJob(),
    ['to' => 'user@example.com'],
    'emails',
    0  // Delay in seconds
);

// Execute immediately
$result = $queueManager->dispatchNow(new ProcessDataJob(), ['data' => $data]);
```

### Creating Queued Jobs

Same as scheduled jobs - implement `IJob` interface:

```php
namespace App\Jobs;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

class SendEmailJob implements IJob
{
    public function getName(): string
    {
        return 'send-email';
    }

    public function run(array $args = []): mixed
    {
        $to = $args['to'];
        $subject = $args['subject'];
        $body = $args['body'];

        Log::info("Sending email to {$to}");

        // Your email sending logic
        mail($to, $subject, $body);

        return true;
    }
}
```

With `queue.bootstrap` configured, jobs are created through the application
container, so constructor dependencies are injected.

#### Per-Job Settings (optional)

Implement `Neuron\Jobs\Queue\IQueueable` as well to override the queue-wide
configuration for one job. Return `null` from any getter to keep the default.

```php
class ChargeCardJob implements IJob, IQueueable
{
    public function getQueue(): ?string    { return 'payments'; } // used when dispatch() names no queue
    public function getMaxAttempts(): ?int { return 1; }
    public function getBackoff(): ?int     { return null; }
    public function getTimeout(): ?int     { return 30; }         // seconds; overrides --timeout

    public function failed(\Throwable $e): void
    {
        // Called once after the final attempt has been stored as failed
    }

    // getName() / run() as above
}
```

### Testing Code That Dispatches Jobs

```php
use Neuron\Jobs\Testing\QueueAssertions;

class RegistrationTest extends \PHPUnit\Framework\TestCase
{
    use QueueAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQueue();   // in-memory ArrayQueue; nothing executes
    }

    public function testQueuesWelcomeEmail(): void
    {
        $this->service->register('a@example.com');

        $this->assertDispatched(SendEmailJob::class, fn(array $args) => $args['to'] === 'a@example.com');
        $this->assertDispatchedOn('emails', SendEmailJob::class);
        $this->assertDispatchedTimes(SendEmailJob::class, 1);
        $this->assertNotDispatched(ChargeCardJob::class);
    }
}
```

Also available: `assertNothingDispatched()`, `getFakeQueue()` for direct
inspection, and `QueueManager::fromRegistry()->processNextJob()` to run the
next job as a worker would. The previous queue manager is restored after each
test.

## Queue Management

### Monitor Queue Status

```bash
# Show statistics for default queue
vendor/bin/neuron jobs:stats

# Show statistics for specific queues
vendor/bin/neuron jobs:stats --queue=emails,reports
```

Per queue this prints pending jobs, delayed jobs (not yet due), jobs in
progress (reserved by a worker) and the age of the oldest pending job, then
the failed-job count. A growing oldest-pending age with idle workers usually
means a stuck reservation or too few workers for that queue. Drivers expose
these through `Neuron\Jobs\Queue\IQueueStats`; a driver that does not
implement it reports pending only.

### List Waiting Jobs

```bash
# Pending jobs on the default queue
vendor/bin/neuron jobs:list

# Delayed jobs on a queue, paged
vendor/bin/neuron jobs:list --queue=emails --state=delayed --limit=20 --offset=20

# What workers are holding right now
vendor/bin/neuron jobs:list --state=reserved
```

Each job prints its id, class, attempts, how long it has waited (or until it
runs, or how long a worker has held it) and an arguments preview. The
database, file, array and redis drivers support this through
`Neuron\Jobs\Queue\IQueueInspector`; the sync driver has nothing to list.

`IQueueInspector` is also the programmatic way to inspect a store:
`listJobs()`, `countJobs()`, `findJob()`, `deleteJob()` (pending or delayed
only), `releaseNow()` (make a delayed job runnable), and paginated
`listFailedJobs()` / `countFailedJobs()` / `findFailedJob()`. `QueueManager`
exposes the same methods and `supportsInspection()`.

### Managing Failed Jobs

A job fails when `run()` throws, or when it returns `false`: `IJob` defines the
return value as the job's success/failure status. Both go through the same
path - retry with backoff up to `max_attempts` (or the job's own
`getMaxAttempts()`), then failed-job storage and the `failed()` hook. Any other
return value, including `null`, is success, so return `null` rather than
`false` when a job simply had nothing to do.

#### View Failed Jobs

```bash
vendor/bin/neuron jobs:failed

# Page through a long list, or narrow to one queue
vendor/bin/neuron jobs:failed --limit=20 --offset=20
vendor/bin/neuron jobs:failed --queue=emails
```

Shows:
- Job ID
- Queue name
- Job class
- Failure timestamp
- Exception details

The first 50 are shown unless `--limit` says otherwise; the footer tells you
the `--offset` for the next page.

#### Retry Failed Jobs

```bash
# Retry specific job by ID
vendor/bin/neuron jobs:retry 123

# Retry all failed jobs
vendor/bin/neuron jobs:retry --all
```

#### Delete Failed Jobs

```bash
# Delete specific failed job
vendor/bin/neuron jobs:forget 123
```

### Clear Queues

```bash
# Clear default queue
vendor/bin/neuron jobs:flush

# Clear specific queue
vendor/bin/neuron jobs:flush --queue=emails

# Clear all failed jobs
vendor/bin/neuron jobs:flush --failed
```

## Helper Functions

```php
// Dispatch to queue
dispatch(IJob $job, array $args = [], ?string $queue = null, int $delay = 0): string

// Execute immediately
dispatchNow(IJob $job, array $args = []): mixed

// Get queue size
queueSize(?string $queue = null): int

// Clear queue
clearQueue(?string $queue = null): int
```


## More Information

Learn more at [neuronphp.com](http://neuronphp.com)
