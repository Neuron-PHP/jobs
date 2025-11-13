[![CI](https://github.com/Neuron-PHP/jobs/actions/workflows/ci.yml/badge.svg)](https://github.com/Neuron-PHP/jobs/actions)
# Neuron-PHP Job Scheduler & Queue

A lightweight job scheduler and queue system for PHP 8.4+. Schedule recurring tasks with cron expressions and process background jobs with a reliable queue system.

## Features

- **Combined Runner**: Single command to run scheduler and worker together
- **Job Scheduling**: Schedule recurring jobs with cron expressions
- **Queue System**: Background job processing with retry logic
- **Multiple Drivers**: Database, file, or synchronous execution
- **Failed Job Management**: Track, retry, and monitor failed jobs
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
  driver: database           # database, file, or sync
  default: default           # Default queue name
  retry_after: 90           # Seconds before retrying failed jobs
  max_attempts: 3           # Maximum retry attempts
  backoff: 10               # Backoff multiplier for retries

# Database configuration (for database driver)
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
```

**Queue Drivers:**

- **database**: Persistent queue in database (recommended for production)
- **file**: File-based queue in storage directory
- **sync**: Execute jobs immediately (for testing)

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

### 1. Combined Mode (Recommended)

Run both scheduler and queue worker together with a single command:

```bash
vendor/bin/neuron jobs:run
```

This is the **easiest way** to run the complete job system. It manages both the scheduler and worker in one process.

**Options:**
- `--schedule-interval=30` - Scheduler polling interval in seconds (default: 60)
- `--queue=emails,default` - Queue(s) to process (default: default)
- `--worker-sleep=5` - Worker sleep when queue is empty (default: 3)
- `--worker-timeout=120` - Job timeout in seconds (default: 60)
- `--max-jobs=100` - Max jobs before restarting worker (default: unlimited)
- `--no-scheduler` - Run only the worker
- `--no-worker` - Run only the scheduler

**Examples:**
```bash
# Run with defaults
vendor/bin/neuron jobs:run

# Custom configuration
vendor/bin/neuron jobs:run --schedule-interval=30 --queue=emails,notifications

# Only run scheduler
vendor/bin/neuron jobs:run --no-worker

# Only run worker
vendor/bin/neuron jobs:run --no-scheduler
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

**Cron Mode** (single poll per invocation):

Add to your system crontab:

```cron
* * * * * cd /path/to/app && vendor/bin/neuron jobs:schedule --poll
```

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
```

**Worker Options:**
- `--queue, -Q`: Queue(s) to process (comma-separated), default: `default`
- `--once`: Process one job then exit
- `--stop-when-empty`: Stop when no jobs available
- `--sleep, -s`: Seconds to sleep when queue empty, default: `3`
- `--max-jobs, -m`: Maximum jobs before stopping, default: `0` (unlimited)
- `--timeout, -t`: Job timeout in seconds, default: `60`

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
use Neuron\Patterns\Registry;

$queueManager = Registry::getInstance()->get('queue.manager');

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

## Queue Management

### Monitor Queue Status

```bash
# Show statistics for default queue
vendor/bin/neuron jobs:stats

# Show statistics for specific queues
vendor/bin/neuron jobs:stats --queue=emails,reports
```

### Managing Failed Jobs

#### View Failed Jobs

```bash
vendor/bin/neuron jobs:failed
```

Shows:
- Job ID
- Queue name
- Job class
- Failure timestamp
- Exception details

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
