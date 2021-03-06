<?php
/**
 * @version     $Id$
 * @package     JSNTplFramework
 * @subpackage  Http
 * @author      JoomlaShine Team <support@joomlashine.com>
 * @copyright   Copyright (C) 2012 JoomlaShine.com. All Rights Reserved.
 * @license     GNU/GPL v2 or later http://www.gnu.org/licenses/gpl-2.0.html
 * 
 * Websites: http://www.joomlashine.com
 * Technical Support:  Feedback - http://www.joomlashine.com/contact-us/get-support.html
 */

// No direct access to this file
defined('_JEXEC') or die('Restricted access');

/**
 * Http Socket Adapter
 * 
 * @package     Http
 * @subpackage  Adapter
 * @since       1.0.0
 */
class JSNTplHttpAdapterSocket extends JSNTplHttpAdapter
{
	/**
	 * Retrieve HTTP response header from an URL
	 * 
	 * @param   string  $url      URL to request
	 * @param   array   $headers  Custom headers for this request
	 * 
	 * @return  boolean
	 */
	public function head ($url, array $headers = array())
	{
		$uri  = $this->_parseURL($url);
		$path = !isset($uri['query']) ? $uri['path'] : $uri['path'] . '?' . $uri['query'];

		// General header
		$requestHeaders = array(
			'host' 			=> $uri['host'],
			'user-agent'	=> $this->_options[JSNTPLHttpClient::USER_AGENT],
			'connection' 	=> 'close'
		);

		// Apply custom headers
		$requestHeaders = array_merge($requestHeaders, $headers);

		$response = $this->_request($this->_createConnection($uri), $this->_buildHeaders('HEAD', $path, $requestHeaders));
		$response->url = $url;
		$response->requestHeaders = $requestHeaders;

		return $response;
	}

	/**
	 * Make a request that use GET as request method
	 * 
	 * @param   string  $url      URL to request
	 * @param   array   $headers  Custom headers for this request
	 * 
	 * @return  boolean
	 */
	public function get ($url, array $headers = array())
	{
		$lastResponse 	= $this->_getLastResponse($url, $headers);
		$uri			= $this->_parseURL($lastResponse->url);
		$path = !isset($uri['query']) ? $uri['path'] : $uri['path'] . '?' . $uri['query'];

		// General header
		$requestHeaders = array(
			'host' 			=> $uri['host'],
			'user-agent'	=> $this->_options[JSNTPLHttpClient::USER_AGENT],
			'connection' 	=> 'close'
		);

		// Apply custom headers
		$requestHeaders = array_merge($requestHeaders, $headers);
		$requestHeaders = $this->_buildHeaders('GET', $path, $requestHeaders);

		return $this->_request($this->_createConnection($uri), $requestHeaders);
	}

	/**
	 * Make a POST request to an URL
	 * 
	 * @param   string  $url      URL to request
	 * @param   array   $data     Data that will be posted to the URL
	 * @param   array   $headers  Custom headers for this request
	 * 
	 * @return  boolean
	 */
	public function post ($url, array $data = array(), array $headers = array())
	{
		$uri 		= $this->_parseURL($url);
		$postData	= http_build_query($data);
		$path		= !isset($uri['query']) ? $uri['path'] : $uri['path'] . '?' . $uri['query'];

		// General header
		$requestHeaders = array(
			'host' 				=> $uri['host'],
			'user-agent' 		=> $this->_options[JSNTPLHttpClient::USER_AGENT],
			'content-type'		=> 'application/x-www-form-urlencoded',
			'content-length'	=> strlen($postData),
			'connection' 		=> 'close'
		);

		// Apply custom headers
		//$requestHeaders  = array_merge($requestHeaders, $headers);
		$requestHeaders  = $this->_buildHeaders('POST', $path, $requestHeaders);
		$requestHeaders .= $postData;

		return $this->_request($this->_createConnection($uri), $requestHeaders);
	}

	/**
	 * Create a HTTP Request to download a file from another server
	 * 
	 * @param   string  $url          URL to the file
	 * @param   array   $destination  Path to save file
	 * @param   array   $headers      Custom headers for this request
	 * 
	 * @return  boolean
	 */
	public function download ($url, $destination, array $headers = array())
	{
		$lastResponse 	= $this->_getLastResponse($url, $headers);
		$uri			= $this->_parseURL($lastResponse->url);
		// File information
		$filename 		= basename($uri['path']);
		// Parse file name from header
		if (isset($lastResponse->headers['content-disposition'])
			&& preg_match('/filename=(.*)/i', $lastResponse->headers['content-disposition'], $matched))
			$filename = trim($matched[1], '"');

		$filePath = is_dir($destination) ? $destination . DIRECTORY_SEPARATOR . $filename : $destination;
		// Destination file
		$fileHandle = fopen($filePath, 'wb+');
		// Checking file open state
		if ($fileHandle === false)
			throw new Exception('Cannot create file at: ' . $filePath);
		$connection = $this->_createConnection($uri);
		$requestHeaders = array(
			'host'			=> $uri['host'],
			'user-agent'	=> $this->_options[JSNTPLHttpClient::USER_AGENT],
		);
		$requestHeaders = array_merge($requestHeaders, $lastResponse->requestHeaders);
		$requestHeaders['connection'] = 'close';
		$isHeaderEnded = false;

		if (isset($uri['query']) && !empty($uri['query']))
			$uri['path'] .= "?{$uri['query']}";

		$requestHeaders = $this->_buildHeaders('GET', $uri['path'], $requestHeaders);
		// Send header
		fwrite($connection, $requestHeaders);
		// Start download file
		while (!feof($connection))
		{
			$buffer = fread($connection, $this->_options[JSNTPLHttpClient::BUFFER_SIZE]);

			if (false !== strpos($buffer, "\r\n\r\n"))
			{
				$buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + strlen("\r\n\r\n"));
				$isHeaderEnded = true;
			}

			if (true === $isHeaderEnded)
				fwrite($fileHandle, $buffer);
		}

		fclose($fileHandle);
		fclose($connection);

		return $lastResponse;
	}

	/**
	 * Find last URL after redirected
	 * 
	 * @param   string  $url      Beginning URL
	 * @param   array   $headers  Custom headers
	 * 
	 * @return  string
	 */
	private function _getLastResponse ($url, $headers = array())
	{
		if ($this->_options[JSNTPLHttpClient::FOLLOW_LOCATION] == false)
			return $url;

		// Get response header to detect redirection
		$headResponse = $this->head($url, $headers);

		while (isset($headResponse->headers['location']) && $this->_redirectedTimes < $this->_options[JSNTPLHttpClient::MAX_REDIRECTS])
		{
			$this->_redirectedTimes++;

			$requestHeaders = array();
			foreach ($headResponse->cookies as $cookie) {
				$requestHeaders[] = "Cookie: {$cookie['name']}={$cookie['value']}";
			}

			$headResponse = $this->head($headResponse->headers['location'], $requestHeaders);
		}

		return $headResponse;
	}

	/**
	 * Open a connection
	 * 
	 * @param   array  $uri  Parsed url information
	 * 
	 * @return  boolean
	 */
	private function _createConnection ($uri)
	{
		$hostname = $uri['protocol'] == 'ssl' ? "ssl://{$uri['host']}" : $uri['host'];
		$errorNum = 0;
		$errorMsg = '';

		// Create new connection to $hostname
		$connection = @fsockopen($hostname, $uri['port'], $errorNum, $errorMsg, $this->_options[JSNTPLHttpClient::CONNECTION_TIMEOUT]);

		if ($errorNum > 0 || !empty($errorMsg))
			throw new Exception($errorMsg);

		// Disable stream blocking
		stream_set_blocking($connection, 0);

		return $connection;
	}

	/**
	 * Send a request to an connection
	 * 
	 * @param   resource  $connection  Existing connection to send headers
	 * @param   string    $headers     Header string that will sent to server
	 * 
	 * @return  object
	 */
	private function _request ($connection, $headers)
	{
		if (is_resource($connection))
		{
			// Send request header
			fwrite($connection, $headers);

			// Read all content
			$content = '';
			while (!feof($connection))
				$content .= fread($connection, $this->_options[JSNTPLHttpClient::BUFFER_SIZE]);

			return $this->_parseResponse($content);
		}

		return null;
	}
}
