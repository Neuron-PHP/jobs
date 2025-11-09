# Neuron Queue System Documentation

## Overview

The Neuron Queue system provides robust asynchronous job processing capabilities. It complements the existing scheduled jobs system by allowing applications to dispatch jobs for background processing, improving response times and enabling deferred execution of time-consuming tasks.

## Features

- **Multiple Queue Drivers**: Database (default), File, Sync (for testing)
- **Named Queues**: Process different job types with priority
- **Automatic Retry**: Exponential backoff with configurable max attempts
- **Failed Job Management**: Track, inspect, retry, and delete failed jobs
- **Graceful Shutdown**: Workers handle SIGTERM/SIGINT signals properly
- **Delayed Jobs**: Schedule jobs to run at specific times
- **Simple API**: Global helper functions for easy job dispatching
- **Worker Daemon**: Continuous background job processing
- **Horizontal Scaling**: Run multiple workers concurrently

## Installation

The queue system is included in the `neuron-php/jobs` component. Install via Composer:

```bash
composer require neuron-php/jobs
```

### Database Setup

If using the database queue driver (recommended), run the migration:

1. Copy the migration stub to your migrations directory:
   ```bash
   cp vendor/neuron-php/jobs/resources/migrations/create_queue_tables.php.stub db/migrate/YYYYMMDDHHMMSS_create_queue_tables.php
   ```

2. Update the class name to match the filename

3. Run the migration:
   ```bash
   php neuron db:migrate
   ```

This creates two tables:
- `jobs` - Stores queued jobs
- `failed_jobs` - Stores failed jobs for inspection

## Configuration

Configure the queue system in `config/config.yaml`:

```yaml
# Queue driver: database, file, or sync
queue.driver: database

# Default queue name
queue.default: default

# Retry settings
queue.retry_after: 90    # seconds before retry
queue.max_attempts: 3     # max retry attempts
queue.backoff: 0          # backoff multiplier (0 = no backoff)

# Database configuration (inherited from main database config)
database.adapter: sqlite
database.name: storage/database.sqlite3

# File queue settings (if using file driver)
queue.file_path: storage/queue
```

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `queue.driver` | string | `database` | Queue driver: `database`, `file`, or `sync` |
| `queue.default` | string | `default` | Default queue name |
| `queue.retry_after` | int | `90` | Seconds before job is retried |
| `queue.max_attempts` | int | `3` | Maximum retry attempts |
| `queue.backoff` | int | `0` | Backoff multiplier for exponential backoff |
| `queue.file_path` | string | `storage/queue` | Path for file queue storage |

### Queue Drivers

#### Database Driver (Recommended)

Best for production. Reliable, supports concurrent workers, and provides ACID guarantees.

```yaml
queue.driver: database
```

#### File Driver

Simple file-based queue. No database required, but not suitable for high-throughput applications.

```yaml
queue.driver: file
queue.file_path: storage/queue
```

#### Sync Driver

Executes jobs immediately (synchronously). Useful for testing and local development.

```yaml
queue.driver: sync
```

## Creating Jobs

Jobs must implement the `Neuron\Jobs\IJob` interface:

```php
<?php

namespace App\Jobs;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

class SendWelcomeEmailJob implements IJob
{
    public function getName(): string
    {
        return 'send_welcome_email';
    }

    public function run( array $args = [] ): mixed
    {
        $email = $args['email'] ?? null;
        $name = $args['name'] ?? 'User';

        if( !$email )
        {
            throw new \InvalidArgumentException( 'Email is required' );
        }

        Log::info( "Sending welcome email to: {$email}" );

        // Send email logic here
        sendEmailTemplate( $email, 'Welcome!', 'emails/welcome', [
            'name' => $name
        ]);

        Log::info( "Welcome email sent successfully" );

        return true;
    }
}
```

## Dispatching Jobs

### Using Helper Functions

#### Basic Dispatch

```php
use App\Jobs\SendWelcomeEmailJob;

// Dispatch to default queue
dispatch( new SendWelcomeEmailJob(), [
    'email' => 'user@example.com',
    'name' => 'John Doe'
]);
```

#### Specific Queue

```php
// Dispatch to 'emails' queue
dispatch(
    new SendWelcomeEmailJob(),
    ['email' => 'user@example.com'],
    'emails'
);
```

#### Delayed Job

```php
// Delay execution by 1 hour (3600 seconds)
dispatch(
    new SendReminderJob(),
    ['order_id' => 123],
    'default',
    3600
);
```

#### Immediate Execution

```php
// Execute immediately, bypassing the queue
$result = dispatchNow(
    new ProcessDataJob(),
    ['data' => $data]
);
```

### Using QueueManager

```php
use Neuron\Jobs\Queue\QueueManager;

$queueManager = new QueueManager();

// Dispatch job
$jobId = $queueManager->dispatch(
    new SendWelcomeEmailJob(),
    ['email' => 'user@example.com'],
    'emails',
    0
);

// Execute immediately
$result = $queueManager->dispatchNow(
    new ProcessDataJob(),
    ['data' => $data]
);
```

## Processing Jobs

### Starting a Worker

#### Daemon Mode (Recommended for Production)

```bash
# Start worker in daemon mode
php neuron jobs:work

# Process specific queue
php neuron jobs:work --queue=emails

# Process multiple queues with priority (high first, then default, then low)
php neuron jobs:work --queue=high,default,low
```

#### One-Time Execution

```bash
# Process one job then exit
php neuron jobs:work --once

# Stop when queue is empty
php neuron jobs:work --stop-when-empty
```

#### Worker Options

```bash
# Custom sleep time when queue is empty (default: 3 seconds)
php neuron jobs:work --sleep=5

# Max jobs to process before stopping (default: 0 = unlimited)
php neuron jobs:work --max-jobs=100

# Job timeout in seconds (default: 60)
php neuron jobs:work --timeout=120
```

### Running Multiple Workers

For high-throughput applications, run multiple workers concurrently:

```bash
# Terminal 1: High priority queue
php neuron jobs:work --queue=high

# Terminal 2: Default queue
php neuron jobs:work --queue=default

# Terminal 3: Low priority queue
php neuron jobs:work --queue=low
```

### Supervisor Configuration

Use a process manager like Supervisor to keep workers running:

```ini
[program:neuron-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/app/neuron jobs:work --queue=default
autostart=true
autorestart=true
user=www-data
numprocs=3
redirect_stderr=true
stdout_logfile=/path/to/app/storage/logs/worker.log
```

## Failed Jobs

### Viewing Failed Jobs

```bash
php neuron jobs:failed
```

Output example:
```
Failed Jobs (2):
────────────────────────────────────────────────────────────────────────────────

ID: job_67890.12345
Queue: emails
Job: App\Jobs\SendWelcomeEmailJob
Failed: 2025-01-08 10:30:00
Exception:
RuntimeException: SMTP connection failed
#0 /path/to/EmailService.php(123): send()
#1 /path/to/SendWelcomeEmailJob.php(45): sendEmail()
────────────────────────────────────────────────────────────────────────────────
```

### Retrying Failed Jobs

```bash
# Retry specific job
php neuron jobs:retry job_67890.12345

# Retry all failed jobs
php neuron jobs:retry --all
```

### Deleting Failed Jobs

```bash
# Delete specific failed job
php neuron jobs:forget job_67890.12345

# Clear all failed jobs
php neuron jobs:flush --failed
```

## Queue Management

### Queue Statistics

```bash
php neuron jobs:stats

# Multiple queues
php neuron jobs:stats --queue=default,emails,processing
```

Output example:
```
Queue Statistics
════════════════════════════════════════════════════════════

Queue: default
  Pending jobs: 42

Queue: emails
  Pending jobs: 15

Failed Jobs: 3

════════════════════════════════════════════════════════════
Total pending jobs: 57
Total failed jobs: 3
```

### Clearing Queues

```bash
# Clear default queue
php neuron jobs:flush

# Clear specific queue
php neuron jobs:flush --queue=emails

# Clear all failed jobs
php neuron jobs:flush --failed
```

## Common Patterns

### Email Notifications

```php
// In controller
public function register(): void
{
    $user = $this->userRepository->create( $userData );

    // Queue welcome email
    dispatch( new SendWelcomeEmailJob(), [
        'email' => $user->getEmail(),
        'name' => $user->getUsername()
    ], 'emails' );

    // Immediate response to user
    redirect( '/welcome' );
}
```

### Event Listeners

```php
use Neuron\Events\IListener;
use Neuron\Events\IEvent;

class SendOrderConfirmationListener implements IListener
{
    public function handle( IEvent $event ): void
    {
        $order = $event->getOrder();

        // Queue order confirmation email
        dispatch( new SendOrderConfirmationJob(), [
            'order_id' => $order->getId(),
            'email' => $order->getCustomerEmail()
        ], 'emails' );
    }
}
```

### Scheduled Jobs Dispatching Queue Jobs

```php
use Neuron\Jobs\IJob;

class DailyReportJob implements IJob
{
    public function getName(): string
    {
        return 'daily_report';
    }

    public function run( array $args = [] ): mixed
    {
        $users = $this->getUsersForReport();

        // Queue individual report emails
        foreach( $users as $user )
        {
            dispatch( new SendReportEmailJob(), [
                'user_id' => $user->getId()
            ], 'emails' );
        }

        return count( $users );
    }
}
```

### Image Processing

```php
// Controller
public function uploadImage(): void
{
    $path = $this->saveUploadedFile( $_FILES['image'] );

    // Queue image processing
    dispatch( new ProcessImageJob(), [
        'path' => $path,
        'sizes' => ['thumb', 'medium', 'large']
    ], 'processing' );

    respond()->json([ 'status' => 'processing' ]);
}

// Job
class ProcessImageJob implements IJob
{
    public function run( array $args = [] ): mixed
    {
        $path = $args['path'];
        $sizes = $args['sizes'];

        foreach( $sizes as $size )
        {
            $this->resizeImage( $path, $size );
        }

        return true;
    }
}
```

### Rate Limiting

```php
class SendApiRequestJob implements IJob
{
    public function run( array $args = [] ): mixed
    {
        // Rate limit: 1 request per second
        sleep( 1 );

        $response = $this->apiClient->request( $args['endpoint'], $args['data'] );

        return $response;
    }
}

// Dispatch multiple requests
foreach( $endpoints as $endpoint )
{
    dispatch( new SendApiRequestJob(), [
        'endpoint' => $endpoint,
        'data' => $data
    ], 'api-requests' );
}
```

### Batch Processing

```php
class ImportDataJob implements IJob
{
    public function run( array $args = [] ): mixed
    {
        $file = $args['file'];
        $batchSize = 1000;

        $rows = $this->readCSV( $file );
        $batches = array_chunk( $rows, $batchSize );

        foreach( $batches as $index => $batch )
        {
            dispatch( new ProcessBatchJob(), [
                'batch' => $batch,
                'batch_number' => $index + 1
            ], 'processing' );
        }

        return count( $batches );
    }
}
```

## Best Practices

### 1. Keep Jobs Small and Focused

```php
// Good: Single responsibility
class SendEmailJob implements IJob { /* ... */ }
class ProcessImageJob implements IJob { /* ... */ }

// Avoid: Multiple responsibilities
class SendEmailAndProcessImageJob implements IJob { /* ... */ }
```

### 2. Handle Failures Gracefully

```php
public function run( array $args = [] ): mixed
{
    try
    {
        $this->doWork( $args );
        return true;
    }
    catch( \Exception $e )
    {
        Log::error( "Job failed: " . $e->getMessage() );
        throw $e; // Re-throw for retry logic
    }
}
```

### 3. Use Specific Queues for Priority

```php
// High priority
dispatch( new ProcessPaymentJob(), $data, 'high' );

// Normal priority
dispatch( new SendEmailJob(), $data, 'default' );

// Low priority
dispatch( new GenerateReportJob(), $data, 'low' );
```

### 4. Monitor Failed Jobs

Regularly check for failed jobs and investigate failures:

```bash
php neuron jobs:failed
```

### 5. Set Appropriate Timeouts

```bash
# Short jobs
php neuron jobs:work --timeout=30

# Long-running jobs
php neuron jobs:work --timeout=300
```

### 6. Use Delayed Jobs for Scheduled Actions

```php
// Send reminder 24 hours later
dispatch(
    new SendReminderJob(),
    ['order_id' => $order->getId()],
    'default',
    86400  // 24 hours in seconds
);
```

### 7. Test with Sync Driver

```yaml
# config/config.test.yaml
queue.driver: sync
```

This executes jobs immediately during tests.

## Troubleshooting

### Jobs Not Processing

**Check worker is running:**
```bash
ps aux | grep "jobs:work"
```

**Check queue size:**
```bash
php neuron jobs:stats
```

**Check logs:**
```bash
tail -f storage/logs/app.log
```

### High Memory Usage

- Reduce `--max-jobs` value
- Restart workers periodically
- Check for memory leaks in job code

### Jobs Timing Out

- Increase `--timeout` value
- Break large jobs into smaller chunks
- Use batch processing pattern

### Failed Jobs Accumulating

- Review exception messages: `php neuron jobs:failed`
- Fix underlying issues
- Retry failed jobs: `php neuron jobs:retry --all`

## API Reference

### Helper Functions

#### `dispatch(IJob $job, array $args, ?string $queue, int $delay): string`
Queue a job for background processing.

#### `dispatchNow(IJob $job, array $args): mixed`
Execute a job immediately, bypassing the queue.

#### `getQueueManager(): QueueManager`
Get the queue manager instance.

#### `queueSize(?string $queue): int`
Get the number of jobs in a queue.

#### `clearQueue(?string $queue): int`
Clear all jobs from a queue.

### QueueManager Methods

#### `dispatch(IJob $job, array $args, ?string $queue, int $delay): string`
Dispatch a job to the queue.

#### `dispatchNow(IJob $job, array $args): mixed`
Execute a job immediately.

#### `processNextJob(?string $queue): bool`
Process the next available job.

#### `size(?string $queue): int`
Get queue size.

#### `clear(?string $queue): int`
Clear queue.

#### `getFailedJobs(): array`
Get all failed jobs.

#### `retryFailedJob(string $id): bool`
Retry a specific failed job.

#### `retryAllFailedJobs(): int`
Retry all failed jobs.

#### `forgetFailedJob(string $id): bool`
Delete a failed job.

#### `clearFailedJobs(): int`
Clear all failed jobs.

## CLI Commands Reference

### `jobs:work`
Start the queue worker.

**Options:**
- `--queue=<name>` - Queue(s) to process (comma-separated)
- `--once` - Process one job then exit
- `--stop-when-empty` - Stop when queue is empty
- `--sleep=<seconds>` - Sleep time when queue is empty
- `--max-jobs=<number>` - Max jobs before stopping
- `--timeout=<seconds>` - Job timeout

### `jobs:failed`
List all failed jobs with exception details.

### `jobs:retry <id>`
Retry a failed job by ID.

**Options:**
- `--all` - Retry all failed jobs

### `jobs:forget <id>`
Delete a failed job by ID.

### `jobs:flush`
Clear jobs from queue.

**Options:**
- `--queue=<name>` - Queue to flush
- `--failed` - Flush failed jobs instead

### `jobs:stats`
Show queue statistics.

**Options:**
- `--queue=<names>` - Queues to show stats for (comma-separated)

## Related Documentation

- [Job Scheduling](readme.md) - Cron-based scheduled jobs
- [Event System](../events/readme.md) - Event-driven architecture
- [Email System](../cms/EMAIL.md) - Email sending capabilities

## See Also

- [Laravel Queues Documentation](https://laravel.com/docs/queues)
- [Background Jobs Best Practices](https://github.com/bensheldon/good_job)
