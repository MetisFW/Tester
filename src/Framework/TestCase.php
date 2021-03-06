<?php

/**
 * This file is part of the Nette Tester.
 * Copyright (c) 2009 David Grudl (http://davidgrudl.com)
 */

namespace Tester;


/**
 * Single test case.
 */
class TestCase
{
	/** @internal */
	const LIST_METHODS = 'nette-tester-list-methods',
		METHOD_PATTERN = '#^test[A-Z0-9_]#';

	/** @var ITestCaseListener[] */
	private $listeners = array();

	/**
	 * Add listener for TestCaseEvents
	 */
	public function addListener(ITestCaseListener $listener) {
		$this->listeners[] = $listener;
	}

	private function onEvent($name) {
		foreach($this->listeners as $listener) {
			if(method_exists($listener, $name)) {
				$args = array_merge(array($this), array_slice(func_get_args(), 1));
				call_user_func_array(array($listener, $name), $args);
			}
		}
	}

	/**
	 * Runs the test case.
	 * @return void
	 */
	public function run($method = NULL)
	{
		$r = new \ReflectionObject($this);
		$methods = array_values(preg_grep(self::METHOD_PATTERN, array_map(function (\ReflectionMethod $rm) {
			return $rm->getName();
		}, $r->getMethods())));

		if (substr($method, 0, 2) === '--') { // back compatibility
			$method = NULL;
		}

		if ($method === NULL && isset($_SERVER['argv']) && ($tmp = preg_filter('#(--method=)?([\w-]+)$#Ai', '$2', $_SERVER['argv']))) {
			$method = reset($tmp);
			if ($method === self::LIST_METHODS) {
				Environment::$checkAssertions = FALSE;
				header('Content-Type: text/plain');
				echo '[' . implode(',', $methods) . ']';
				return;
			}
		}

		if ($method === NULL) {
			foreach ($methods as $method) {
				$this->runTest($method);
			}
		} elseif (in_array($method, $methods, TRUE)) {
			$this->runTest($method);
		} else {
			throw new TestCaseException("Method '$method' does not exist or it is not a testing method.");
		}
	}


	/**
	 * Runs the test method.
	 * @param  string  test method name
	 * @param  array  test method parameters (dataprovider bypass)
	 * @return void
	 */
	public function runTest($method, array $args = NULL)
	{
		$method = new \ReflectionMethod($this, $method);
		if (!$method->isPublic()) {
			throw new TestCaseException("Method {$method->getName()} is not public. Make it public or rename it.");
		}

		$info = Helpers::parseDocComment($method->getDocComment()) + array('dataprovider' => NULL, 'throws' => NULL);

		if ($info['throws'] === '') {
			throw new TestCaseException("Missing class name in @throws annotation for {$method->getName()}().");
		} elseif (is_array($info['throws'])) {
			throw new TestCaseException("Annotation @throws for {$method->getName()}() can be specified only once.");
		} else {
			$throws = preg_split('#\s+#', $info['throws'], 2) + array(NULL, NULL);
		}

		$data = array();
		if ($args === NULL) {
			$defaultParams = array();
			foreach ($method->getParameters() as $param) {
				$defaultParams[$param->getName()] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : NULL;
			}

			foreach ((array) $info['dataprovider'] as $provider) {
				$res = $this->getData($provider);
				if (!is_array($res)) {
					throw new TestCaseException("Data provider $provider() doesn't return array.");
				}
				foreach ($res as $set) {
					$data[] = is_string(key($set)) ? array_merge($defaultParams, $set) : $set;
				}
			}

			if (!$info['dataprovider']) {
				if ($method->getNumberOfRequiredParameters()) {
					throw new TestCaseException("Method {$method->getName()}() has arguments, but @dataProvider is missing.");
				}
				$data[] = array();
			}
		} else {
			$data[] = $args;
		}

		$this->onEvent("onBeforeRunTest", $method->getName());

		foreach ($data as $params) {
			try {
				$this->onEvent("onBeforeSetUp", $method->getName(), $params);
				$this->setUp();
				$this->onEvent("onAfterSetUp", $method->getName(), $params);

				try {
					if ($info['throws']) {
						$tmp = $this;
						$e = Assert::error(function () use ($tmp, $method, $params) {
							call_user_func_array(array($tmp, $method->getName()), $params);
						}, $throws[0], $throws[1]);
						if ($e instanceof AssertException) {
							throw $e;
						}
					} else {
						call_user_func_array(array($this, $method->getName()), $params);
					}
				} catch (\Exception $testException) {
				}

				try {
					$this->onEvent("onBeforeTearDown", $method->getName(), $params);
					$this->tearDown();
					$this->onEvent("onAfterTearDown", $method->getName(), $params);
				} catch (\Exception $tearDownException) {
				}

				if (isset($testException)) {
					throw $testException;
				} elseif (isset($tearDownException)) {
					throw $tearDownException;
				}

			} catch (AssertException $e) {
				$e->setMessage("$e->origMessage in {$method->getName()}" . (substr(Dumper::toLine($params), 5)));
				$this->onEvent("onTestFail", $method->getName(), $params, $e);
				$this->onEvent("onAfterRunTest", $method->getName());
				throw $e;
			} catch (\Exception $e) {
				$this->onEvent("onTestFail", $method->getName(), $params, $e);
				$this->onEvent("onAfterRunTest", $method->getName());
				throw $e;
			}

			$this->onEvent("onTestPass", $method->getName(), $params);
			$this->onEvent("onAfterRunTest", $method->getName());
		}

	}


	/**
	 * @return array
	 */
	protected function getData($provider)
	{
		if (strpos($provider, '.')) {
			$rc = new \ReflectionClass($this);
			list($file, $query) = DataProvider::parseAnnotation($provider, $rc->getFileName());
			return DataProvider::load($file, $query);
		} else {
			return $this->$provider();
		}
	}


	/**
	 * This method is called before a test is executed.
	 * @return void
	 */
	protected function setUp()
	{
	}


	/**
	 * This method is called after a test is executed.
	 * @return void
	 */
	protected function tearDown()
	{
	}

}


class TestCaseException extends \Exception
{
}
