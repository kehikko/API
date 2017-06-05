<?php

namespace API;

class API extends \Core\Module
{
	private $api        = null;
	private $controller = null;

	public function __construct($controller_or_api, $file = 'api.yaml')
	{
		parent::__construct();

		if (is_array($controller_or_api))
		{
			$this->api = $controller_or_api;
		}
		else
		{
			$this->api        = $controller_or_api->loadCustomConfig($file);
			$this->controller = $controller_or_api;
		}
	}

	public function getConfig()
	{
		return $this->api;
	}

	public function getController()
	{
		return $this->controller;
	}

	public function parse($calls, &$object = null, $new = false, $get_single_array = false)
	{
		if ($this->kernel->method == 'put')
		{
			return $this->parsePut($calls, $object, $new);
		}
		else if ($this->kernel->method == 'post')
		{
			return $this->parsePost($calls, $object, $new);
		}
		else if ($this->kernel->method == 'get')
		{
			$data = array();
			if (is_array($object) && !$get_single_array)
			{
				foreach ($object as $o)
				{
					$ret = $this->parseGet($calls, $o);
					if ($ret === false)
					{
						return false;
					}
					$data[] = $ret;
				}
			}
			else
			{
				$data = $this->parseGet($calls, $object);
			}
			return $data;
		}

		return true;
	}

	public function parseGet($calls, &$object = null)
	{
		$data  = array();
		$calls = is_array($calls) ? $calls : array($calls);
		foreach ($calls as $call)
		{
			if (!isset($this->api[$call]))
			{
				$this->setError('API call not found: ' . $call);
				return false;
			}
			if (!$this->parseGetRecursive($this->api[$call]['get']['values'], $data, $object))
			{
				return false;
			}
		}
		return $data;
	}

	private function parseGetRecursive($api, &$data, &$object = null)
	{
		foreach ($api as $key => $value)
		{
			if (isset($value['method']) && is_string($value['method']))
			{
				$methods = explode(':', $value['method']);
				$v       = $object;
				for ($i = 0; $i < count($methods); $i++)
				{
					if ($v === null)
					{
						break;
					}
					$method = $methods[$i];
					if ($method)
					{
						$v = $v->{$method}();
					}
					else
					{
						$temp_array = array();
						foreach ($v as $o)
						{
							$temp_array[] = $o->{$methods[$i + 1]}();
						}
						$v = $temp_array;
						break;
					}
				}
				/* special case of DateTime */
				if (is_a($v, 'DateTime'))
				{
					if (isset($value['format']) && is_string($value['format']))
					{
						$v = $v->format($value['format']);
					}
					else
					{
						/* as default format to ISO-8601 */
						$v = $v->format('c');
					}
				}
				$data[$key] = $v;
			}
			else if (isset($value['property']) && is_string($value['property']))
			{
				$properties = explode(':', $value['property']);
				$v          = $object;
				for ($i = 0; $i < count($properties); $i++)
				{
					$property = $properties[$i];
					if ($property)
					{
						$v = $v->{$property};
					}
					else
					{
						$temp_array = array();
						foreach ($v as $o)
						{
							$temp_array[] = $o->{$properties[$i + 1]};
						}
						$v = $temp_array;
						break;
					}
				}
				/* special case of DateTime */
				if (is_a($v, 'DateTime'))
				{
					if (isset($value['format']) && is_string($value['format']))
					{
						$v = $v->format($value['format']);
					}
					else
					{
						/* as default format to ISO-8601 */
						$v = $v->format('c');
					}
				}
				$data[$key] = $v;
			}
			else if (isset($value['key']) && is_string($value['key']))
			{
				$keys = explode(':', $value['key']);
				$v    = $object;
				for ($i = 0; $i < count($keys); $i++)
				{
					$subkey = $keys[$i];
					if ($subkey)
					{
						$v = $v[$subkey];
					}
					else
					{
						$temp_array = array();
						foreach ($v as $o)
						{
							$temp_array[] = $o[$keys[$i + 1]];
						}
						$v = $temp_array;
						break;
					}
				}
				/* special case of DateTime */
				if (is_a($v, 'DateTime'))
				{
					if (isset($value['format']) && is_string($value['format']))
					{
						$v = $v->format($value['format']);
					}
					else
					{
						/* as default format to ISO-8601 */
						$v = $v->format('c');
					}
				}
				$data[$key] = $v;
			}
			else if (isset($value['value']) && is_string($value['value']))
			{
				$data[$key] = $value['value'];
			}
			else
			{
				if (!isset($data[$key]) || !is_array($data[$key]))
				{
					$data[$key] = array();
				}
				if (!$this->parseGetRecursive($value, $data[$key], $object))
				{
					return false;
				}
			}
		}

		return true;
	}

	private function parsePut($calls, &$object = null, $new = false)
	{
		$data = @json_decode($this->kernel->put, true);
		if ($data !== null)
		{
			return $this->parseImport($calls, $data, $object, $new);
		}
		$this->setError('Invalid JSON data for API request.');
		return false;
	}

	private function parsePost($calls, &$object = null, $new = false)
	{
		return $this->parseImport($calls, $this->kernel->post, $object, $new);
	}

	private function parseImport($calls, $from_data, &$object = null, $new = false)
	{
		$data  = array();
		$calls = is_array($calls) ? $calls : array($calls);
		foreach ($calls as $call)
		{
			if (!isset($this->api[$call]['put']))
			{
				$this->setError('API call is missing PUT/POST definitions');
				return false;
			}
			if (!$this->parseImportRecursive($this->api[$call]['put']['values'], $from_data, $data, $object, $new))
			{
				return false;
			}
		}
		return $data;
	}

	private function parseImportRecursive($api, $from_data, &$data, &$object = null, $new = false)
	{
		if (!is_array($api))
		{
			return true;
		}
		foreach ($api as $key => $api_value)
		{
			if (isset($api_value['type']) && is_string($api_value['type']))
			{
				if (!$this->parseImportValue($key, $api_value, $from_data, $data, $object, $new))
				{
					return false;
				}
			}
			else
			{
				$subdata = array();
				if (isset($from_data[$key]))
				{
					$subdata = $from_data[$key];
				}
				$data[$key] = array();
				if (!$this->parseImportRecursive($api_value, $subdata, $data[$key], $object, $new))
				{
					return false;
				}
				if (empty($data[$key]))
				{
					unset($data[$key]);
				}
			}
		}

		return true;
	}

	private function parseImportValue($key, $api_value, $from_data, &$data, &$object = null, $new = false)
	{
		$type            = $api_value['type'];
		$required_create = false;
		$required_exists = false;

		if (isset($api_value['required']))
		{
			if (is_bool($api_value['required']))
			{
				$required_create = $api_value['required'];
				$required_exists = $api_value['required'];
			}
			else
			{
				if (array_key_exists('create', $api_value['required']))
				{
					$required_create = $api_value['required']['create'];
				}
				if (array_key_exists('exists', $api_value['required']))
				{
					$required_exists = $api_value['required']['exists'];
				}
			}
		}

		if (!isset($from_data[$key]))
		{
			if ($new)
			{
				if ($required_create)
				{
					$this->setError('Missing required value with key: ' . $key);
					return false;
				}
				else if ($object !== null && isset($api_value['method']) && isset($api_value['default']))
				{
					$object->{$api_value['method']}($api_value['default']);
				}
			}
			else
			{
				if ($required_exists)
				{
					$this->setError('Missing required value with key: ' . $key);
					return false;
				}
			}
			return true;
		}

		if ($new && $required_create === null)
		{
			$this->setError('Value for key is not allowed when creating new: ' . $key);
			return false;
		}
		else if (!$new && $required_exists === null)
		{
			$this->setError('Value for key is not allowed when modifying existing: ' . $key);
			return false;
		}

		$value = $from_data[$key];
		if ($type == 'bool' && ($value == 'true' || $value == 'false'))
		{
			$value = $value == 'true' ? true : false;
		}
		/* check that value content is the right type */
		if (!$this->checkValueType($type, $value, $key))
		{
			$this->setError('Type of value is not valid, need: ' . $type . ', key: ' . $key);
			return false;
		}
		/* force int and float to their right types */
		if ($type == 'int')
		{
			$value = intval($value);
		}
		else if ($type == 'float')
		{
			$value = floatval($value);
		}
		/* check accepted values */
		if (isset($api_value['accept']))
		{
			if (!in_array($value, $api_value['accept']))
			{
				$this->setError('Value not accepted for key: ' . $key);
				return false;
			}
		}
		/* numeric max/min checks */
		if (in_array($type, array('int', 'float', 'number')))
		{
			if (isset($api_value['min']) && $value < $api_value['min'])
			{
				$this->setError('Value too small: ' . $key);
				return false;
			}
			if (isset($api_value['max']) && $value > $api_value['max'])
			{
				$this->setError('Value too big: ' . $key);
				return false;
			}
		}
		/* convert datetime string to DateTime object */
		if ($type == 'datetime')
		{
			$value = date_create($value);
		}

		$data[$key] = $value;

		if ($object !== null)
		{
			if (isset($api_value['key']))
			{
				$object[$api_value['key']] = $value;
			}
			else if (isset($api_value['method']))
			{
				$object->{$api_value['method']}($value);
			}
			else if (isset($api_value['property']))
			{
				$object->{$api_value['property']} = $value;
			}
		}

		return true;
	}

	private function checkValueType($type, $value, $key)
	{
		if ($type == 'string' && is_string($value))
		{
			return true;
		}
		else if ($type == 'int' && filter_var($value, FILTER_VALIDATE_INT) !== false)
		{
			return true;
		}
		else if ($type == 'float' && filter_var($value, FILTER_VALIDATE_FLOAT) !== false)
		{
			return true;
		}
		else if ($type == 'number' && is_numeric($value))
		{
			return true;
		}
		else if ($type == 'bool' && is_bool($value))
		{
			return true;
		}
		else if ($type == 'null' && is_null($value))
		{
			return true;
		}
		else if ($type == 'array' && is_array($value))
		{
			return true;
		}
		else if ($type == 'object' && is_array($value))
		{
			return true;
		}
		else if ($type == 'email' && filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE))
		{
			return true;
		}
		else if ($type == 'ip' && filter_var($value, FILTER_VALIDATE_IP))
		{
			return true;
		}
		else if ($type == 'ipv4' && filter_var($value, FILTER_VALIDATE_IPV4))
		{
			return true;
		}
		else if ($type == 'ipv6' && filter_var($value, FILTER_VALIDATE_IPV6))
		{
			return true;
		}
		else if ($type == 'url' && filter_var($value, FILTER_VALIDATE_URL))
		{
			return true;
		}
		else if ($type == 'datetime' && @date_create($value) !== false)
		{
			return true;
		}
		else if ($type == 'fqdn' && @Validate::FQDN($value) !== false)
		{
			return true;
		}
		else if ($type == 'fqdn-wildcard' && @Validate::FQDN($value, true) !== false)
		{
			return true;
		}

		$this->setError('Invalid value for type ' . $type . ', key: ' . $key);
		return false;
	}
}
