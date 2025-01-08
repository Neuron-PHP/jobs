# Neuron Job Scheduler

## Configuration

### Application
The application configuration is loaded from config/config.yaml

Example config.yaml:
```yaml
logging:
  destination: \Neuron\Log\Destination\StdOut
  format: \Neuron\Log\Format\PlainText
  level: debug

system:
  timezone: US/Eastern
  base_path: /path/to/app
```

### Schedule
The job schedule is loaded from config/schedule.yaml

Example schedule.yaml:
```yaml
schedule:
  testJobWithArgs:
    class: App\Jobs\TestJob
    cron: "5 * * * *"
    args:
      doSomething: true
      dontDoSomething: false

  testJobWithOutArgs:
    class: App\Jobs\TestJob
    cron: "15 * * * *"
```

* class: The class to instantiate and run.
* cron: The cron expression for the job schedule.
* args: An array of arguments to pass to the job.

## Installation
```bash
composer require neuron/jobs
```

## Job Classes
Job classes must implement the `Neuron\Jobs\IJob` interface.

Example Job class:
```php
namespace App\Jobs;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

class TestJob implements IJob
{
    public function getName() : string
    {
        return 'TestJob';
    }

    public function run( array $Argv = [] ) : mixed
    {
        Log::debug( "TestJob::run( {$Argv['interval']} )" );
        return true;
    }
}
```

## Running the Scheduler
### Infinite Polling
```bash
./vendor/bin/schedule
```
This will run the scheduler in an infinite polling loop, polling every 60 seconds by default.

The polling interval seconds can be changed with the `--interval` option.

```bash
./vendor/bin/scheduler --interval 5
```

### Single Poll
The scheduler can also be run to perform a single poll of the schedule by using the `--poll` option.
For best results, the schedule should be run with the `--poll` option in a cron job and ran once per minute.
```bash
./vendor/bin/scheduler --poll
```
