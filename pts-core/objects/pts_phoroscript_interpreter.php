<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2009 - 2010, Phoronix Media
	Copyright (C) 2009 - 2010, Michael Larabel

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

class pts_phoroscript_interpreter
{
	private $script_file;
	private $environmental_variables;
	private $var_current_directory;

	public function __construct($script, $env_vars = null, $set_current_path = null)
	{
		if(!isset($env_vars["HOME"]))
		{
			$env_vars["HOME"] = $set_current_path;
		}

		$this->environmental_variables = $env_vars;
		$this->script_file = is_file($script) ? $script : null;
		$this->var_current_directory = $set_current_path;
	}
	protected function get_real_path($path)
	{
		if(substr($path, 0, 1) == '~')
		{
			$path = $this->environmental_variables["HOME"] . substr($path, 2);
		}
		else if($path == "\$LOG_FILE")
		{
			return $this->environmental_variables["LOG_FILE"];
		}

		if(is_file($path))
		{
			return $path;
		}
		else if(is_file($this->var_current_directory . $path))
		{
			return $this->var_current_directory . $path;
		}
		else
		{
			return pts_add_trailing_slash($this->var_current_directory . $path);
		}
	}
	protected function find_file_in_array(&$string_array)
	{
		$found_file = false;

		foreach($string_array as $segment)
		{
			if(is_file($segment))
			{
				$found_file = $segment;
				break;
			}
			else if(is_file($this->var_current_directory . $segment))
			{
				$found_file = $this->var_current_directory . $segment;
				break;
			}
		}

		return $found_file;
	}
	protected function parse_variables_in_string(&$to_parse, &$pass_arguments)
	{
		$pass_arguments_r = pts_trim_explode(' ', $pass_arguments);
		$offset = -1;

		while(($offset = strpos($to_parse, '$', ($offset + 1))) !== false)
		{
			if($to_parse[($offset - 1)] == "\\")
			{
				continue;
			}

			$var = substr($to_parse, $offset + 1);

			foreach(array("\n", ' ', '-', '.', "\"", '\\') as $token)
			{
				$this_str = strtok($var, $token);

				if($this_str !== false)
				{
					$var = $this_str;
				}
			}

			if($var == null)
			{
				continue;
			}

			$before_var = substr($to_parse, 0, $offset);
			$after_var = substr($to_parse, $offset + 1 + strlen($var));
			$var_value = null;

			if($var == '@')
			{
				$var_value = $pass_arguments;
			}
			if(isset($this->environmental_variables[$var]))
			{
				$var_value = $this->environmental_variables[$var];
			}
			else if(is_numeric($value) && isset($pass_arguments_r[$var]))
			{
				$var_value = $pass_arguments_r[$var];
			}

			if(IS_WINDOWS && $var == "LOG_FILE")
			{
				$value = str_replace('/', '\\', $value);
			}

			$to_parse = $before_var . $var_value . $after_var;
		}
	}
	public function execute_script($pass_arguments = null)
	{
		if($this->script_file == null)
		{
			return false;
		}

		$script_contents = file_get_contents($this->script_file);
		$prev_exit_status = 0;
		$script_pointer = -1;

		do
		{
			$exit_status = 0;

			if($prev_exit_status != 0)
			{
				$exit_status = $prev_exit_status;
				$prev_exit_status = 0;
			}

			$script_contents = substr($script_contents, ($script_pointer + 1));
			$line = $script_contents;
			$prev_script_pointer = $script_pointer;

			if(($script_pointer = strpos($line, "\n")) !== false)
			{
				$line = substr($line, 0, $script_pointer);
			}

			$line_r = pts_trim_explode(' ', $line);

			switch($line_r[0])
			{
				case 'mv':
					// TODO: implement folder support better and glob support
					$line_r[1] = $this->get_real_path($line_r[1]);
					$line_r[2] = $this->get_real_path($line_r[2]);
					pts_remove($line_r[2], null, true);
					rename($line_r[1], $line_r[2]);
					break;
				case 'cp':
					// TODO: implement folder support better and glob support
					$line_r[1] = $this->get_real_path($line_r[1]);
					$line_r[2] = $this->get_real_path($line_r[2]);

					copy($line_r[1], $line_r[2] . (is_dir($line_r[2]) ? basename($line_r[1]) : null));
					break;
				case 'cd':
					if($line_r[1] == '..')
					{
						if(substr($this->var_current_directory, -1) == '/')
						{
							$this->var_current_directory = substr($this->var_current_directory, 0, -1);
						}

						$this->var_current_directory = substr($this->var_current_directory, 0, strrpos($this->var_current_directory, '/') + 1);
					}
					else if($line_r[1] == '~')
					{
						$this->var_current_directory = $this->environmental_variables["HOME"];
					}
					else if(substr($line_r[1], 0, 1) == '"')
					{
						// On Windows some directories are encased in quotes for spaces in the directory names
						array_shift($line_r);
						$this->var_current_directory = implode(' ', $line_r);
					}
					else if(is_readable($line_r[1]))
					{
						$this->var_current_directory = $line_r[1];
					}
					else if(is_readable($this->get_real_path($line_r[1])))
					{
						$this->var_current_directory = $this->get_real_path($line_r[1]);
					}
					break;
				case 'touch':
					if(!is_file($this->var_current_directory . $line_r[1]) && is_writable($this->var_current_directory))
					{
						touch($this->var_current_directory . $line_r[1]);
					}
					break;
				case 'mkdir':
					pts_mkdir($this->var_current_directory . $line_r[1]);
					break;
				case 'rm':
					for($i = 1; $i < count($line_r); $i++)
					{
						if(is_file($this->var_current_directory . $line_r[$i]))
						{
							unlink($this->var_current_directory . $line_r[$i]);
						}
						else if(is_dir($this->var_current_directory . $line_r[$i]))
						{
							pts_remove($this->var_current_directory . $line_r[$i], null, true);
						}
					}
					break;
				case 'chmod':
					$chmod_file = self::find_file_in_array($line_r);

					if($chmod_file)
					{
						chmod($chmod_file, 0755);
					}
					break;
				case 'unzip':
					$zip_file = self::find_file_in_array($line_r);
					pts_zip_archive_extract($zip_file, $this->var_current_directory);
					break;
				case 'tar':
					// TODO: implement, i.e. tar -xvf ../../openarena-benchmark-files-4.tar.gz
					break;
				case 'echo':
					if($line == "echo $? > ~/install-exit-status")
					{
						file_put_contents($this->var_current_directory . "install-exit-status", $exit_status);
						break;
					}
					else if($line == "echo $? > ~/test-exit-status")
					{
						file_put_contents($this->var_current_directory . "test-exit-status", $exit_status);
						break;
					}

					$start_echo = strpos($script_contents, "\"") + 1;
					$end_echo = $start_echo - 1;

					do
					{
						$end_echo = strpos($script_contents, "\"", $end_echo + 1);
					}
					while($script_contents[($end_echo - 1)] == "\\");

					$script_pointer = strpos($script_contents, "\n", $end_echo);
					$line_remainder = substr($script_contents, ($end_echo + 1), ($script_pointer - $end_echo - 1));
					$echo_contents = substr($script_contents, $start_echo, ($end_echo - $start_echo));

					$this->parse_variables_in_string($echo_contents, $pass_arguments);

					$echo_contents = str_replace("\\$", "\$", $echo_contents);
					$echo_contents = str_replace("\\\"", "\"", $echo_contents);

					if(($to_file = strpos($line_remainder, ' > ')) !== false)
					{
						$to_file = trim(substr($line_remainder, $to_file + 3));

						if(($end_file = strpos($to_file, ' ')) !== false)
						{
							$to_file = substr($to_file, 0, $end_file);
						}

						// TODO: right now it's expecting the file location pipe to be relative location
						file_put_contents($this->var_current_directory . $to_file, $echo_contents . "\n");
					}
					else
					{
						echo $echo_contents;
					}
					break;
				case '#!/bin/sh':
				case '#':
				case null:
					// IGNORE
					break;
				case 'case':
					echo "\nUNHANDLED EVENT\n";
					return false;
					// TODO: decide how to handle
					break;

				case '$TIMER_START':
					$timer_start = microtime(true);
					break;
				case '$TIMER_STOP':
					if(isset($timer_start))
					{
						$time_diff = microtime(true) - $timer_start;

						if($time_diff < 3)
						{
							$time_diff = 0;
						}

						file_put_contents($this->environmental_variables["HOME"] . "/pts-timer", $time_diff);
					}
					break;
				default:
					$exec_output = array();

					if(IS_WINDOWS && substr($line, 0, 2) == "./")
					{
						$line = substr($line, 2);
					}

					$this->parse_variables_in_string($line, $pass_arguments);
					$cd_dir = $this->var_current_directory;

					if(IS_WINDOWS)
					{
						$cd_dir = str_replace('/', '\\', $cd_dir);
					}

					exec("cd " . $cd_dir . " && " . $line . " 2>&1", $exec_output, $prev_exit_status);
					break;
			}
		}
		while($script_contents != false);
	}
}

?>
