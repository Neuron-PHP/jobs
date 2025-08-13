[![Build Status](https://app.travis-ci.com/Neuron-PHP/jobs.svg?token=F8zCwpT7x7Res7J2N4vF&branch=master)](https://app.travis-ci.com/Neuron-PHP/jobs)
# Neuron-PHP Job Scheduler

## Overview

## Installation
```bash
composer require neuron-php/jobs
```

Configure the application to use psr-4 autoloading in composer.json as the application
relies on the autoloader to create the job objects.

Composer snippet:
```json
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
````


## Configuration

### Example Directory Structure
```
├── composer.json
├── config
│   ├── config.yaml
│   └── schedule.yaml 
├── src  
│   └── Jobs
│       └── MyJob.php
└── vendor 
```

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
    class: App\Jobs\ImportData
    cron: "5 * * * *"
    args:
      doSomething: true
      dontDoSomething: false

  testJobWithOutArgs:
    class: App\Jobs\SendReminderEmail
    cron: "15 * * * *"
```

* class: The class to instantiate and run.
* cron: The cron expression for the job schedule.
* args: An array of arguments to pass to the job.


### Job Classes
Job classes must implement the `Neuron\Jobs\IJob` interface.

Example Job class:
```php
namespace App\Jobs;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

class ExampleJob implements IJob
{
    public function getName() : string
    {
        return 'TestJob';
    }

    public function run( array $Argv = [] ) : mixed
    {
        Log::debug( "TestJob::run( {$Argv['parameterName']} )" );
        return true;
    }
}
```

## Running the Scheduler
### Infinite Polling
```bash
./vendor/bin/neuron jobs:schedule
```
This will run the scheduler in an infinite polling loop, polling every 60 seconds by default.

The polling interval seconds can be changed with the `--interval` option.

```bash
./vendor/bin/neuron jobs:schedule --interval 5
```

### Single Poll
The scheduler can also be run to perform a single poll of the schedule by using the `--poll` option.
For best results, the schedule should be run with the `--poll` option in a cron job and ran once per minute.
```bash
./vendor/bin/neuron jobs:schedule --poll
```

# More Information

You can read more about the Neuron components at [neuronphp.com](http://neuronphp.com)
