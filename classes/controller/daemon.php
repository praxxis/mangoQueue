<?php defined('SYSPATH') or die('No direct script access.');

/*
 * The task daemon. Reads queued items and executes them
 */

class Controller_Daemon extends Controller_CLI {

	public function before()
	{
		parent::before();

		// Setup
		ini_set("max_execution_time", "0");
		ini_set("max_input_time", "0");
		set_time_limit(0);

		// Signal handler
		pcntl_signal(SIGCHLD, array($this, 'sig_handler'));
		pcntl_signal(SIGTERM, array($this, 'sig_handler'));
		declare(ticks = 1);
	}

	protected $_config;
	protected $_sigterm;
	protected $_pids = array();

	/*
	 * Forks into background and initializes daemon
	 */
	public function action_index($config = 'default')
	{
		// fork into background
		$pid = pcntl_fork();

		if ( $pid == -1)
		{
			// Error - fork failed
			Kohana::$log->add('error', 'Queue. Initial fork failed');
			exit;
		}
		elseif ( $pid)
		{
			// Fork successful - exit parent (daemon continues in child)
			Kohana::$log->add('debug', 'Queue. Daemon created succesfully at: ' . $pid);
			exit;
		}
		else
		{
			// Background process - run daemon
			$this->_config = Kohana::config('daemon')->$config;

			Kohana::$log->add('debug',strtr('Queue. Config :config loaded, max: :max, sleep: :sleep', array(
				':config' => $config,
				':max'    => $this->_config['max'],
				':sleep'  => $this->_config['sleep']
			)));

			// Write the log to ensure no memory issues
			Kohana::$log->write();

			// run daemon
			$this->daemon();
		}
	}

	/*
	 * This is the actual daemon process that reads queued items and executes them
	 */
	protected function daemon()
	{
		while ( ! $this->_sigterm)
		{
			// Find first task that is not being executed
			$task = Mango::factory('task')
				->load(1, array('_id' => 1), array(), array('e' => array('$exists' => FALSE)));

			if ( $task->loaded() && count($this->_pids) < $this->_config['max'])
			{
				// Task found

				// Update task status
				$task->e = TRUE;
				$task->update();

				// Write log to prevent memory issues
				Kohana::$log->write();

				// Fork process to execute task
				$pid = pcntl_fork();

				if ( $pid == -1)
				{
					Kohana::$log->add('error', 'Queue. Could not spawn child task process.');
					exit;
				}
				elseif ( $pid)
				{
					// Parent - add the child's PID to the running list
					$this->_pids[$pid] = time();
				}
				else
				{
					try
					{
						// Child - Execute task
						Request::factory( Route::get( $task->route )->uri( $task->uri->as_array() ) )->execute();
					}
					catch(Exception $e)
					{
						// Task failed - log message
						Kohana::$log->add('error', 'Queue. Task failed - route: :route, uri: :uri, msg: :msg', array(
							':route' => $task->route,
							':uri'   => http_build_query($task->uri->as_array()),
							':msg'   => $e->getMessage()
						));
					}

					// Remove task from queue
					$task->delete();
					exit;
				}
			}
			else
			{
				// No task in queue - sleep
				usleep($this->_config['sleep']);
			}
		}

		// clean up
		$this->clean();
	}

	/*
	 * Performs clean up. Tries (several times if neccesary)
	 * to kill all children
	 */
	protected function clean()
	{
		$tries = 0;

		while ( $tries++ < 5 && count($this->_pids))
		{
			$this->kill_all();
			sleep(1);
		}

		if ( count($this->_pids))
		{
			Kohana::$log('error','Queue. Could not kill all children');
		}
	}

	/*
	 * Tries to kill all running children
	 */
	protected function kill_all()
	{
		foreach ($this->_pids as $pid => $time)
		{
			posix_kill($pid, SIGTERM);
			usleep(500);
		}

		return count($this->_pids) === 0;
	}

	/*
	 * Signal handler. Handles kill & child died signal
	 */
	public function sig_handler($signo)
	{
		switch ($signo)
		{
			case SIGCHLD: 
				// Child died signal
				while( ($pid = pcntl_wait($status, WNOHANG || WUNTRACED)) > 0)
				{
					// remove pid from list
					unset($this->_pids[$pid]);
				}
			break;
			case SIGTERM:
				// Kill signal
				$this->_sigterm = TRUE;
				Kohana::$log->add('debug', 'Queue. Hit a SIGTERM');
			break;
		}
	}
}