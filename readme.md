# Neuron Job Scheduler

Loads jobs from schedule.yaml

Example schedule.yaml:
```
schedule:
  testJobWithArgs:
    class: Jobs\TestJob
    cron: "* * * * *"
    args:
      ""
      "arg2"
```

class: The class to instantiate and run.
cron: The cron expression to schedule the job.
args: An array of arguments to pass to the job.

## Installation
```bash
composer require neuron/jobs
```

## Usage
```bash
./vendor/bin/scheduler
```
This will run the scheduler in an infinite polling loop.

The polling interval can be changed with the `--interval` option.

```bash
./vendor/bin/scheduler --interval 5
```

The scheduler can also be run to perform a single poll of the schedule by using the `--poll` option.

```bash
./vendor/bin/scheduler --poll
```

