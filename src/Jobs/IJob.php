<?php

namespace Neuron\Jobs;

use Neuron\Patterns\IRunnable;

interface IJob extends IRunnable
{
	public function getName() : string;
}
