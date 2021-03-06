<?php

return array(

	'default' => array(

		// Maximum number of tasks that can be executed at the same time (parallel)
		'max' => 4,

		// Sleep time (in microseconds) when there's no active task
		'sleep' => 5000000, // 5 seconds

		// save the PID file in this location
		'pid_path' => '/tmp/'
	)

);