<?php
/***************************************************************
*  Copyright notice
*  
*  (c) 1999-2003 Kasper Sk�rh�j (kasper@typo3.com)
*  All rights reserved
*
*  This script is part of the Typo3 project. The Typo3 project is 
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
* 
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*  A copy is found in the textfile GPL.txt and important notices to the license 
*  from the author is found in LICENSE.txt distributed with these scripts.
*
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
 * Parent class for "Services" classes
 *
 * TODO: temp files are not removed
 *
 * @author	Ren� Fritz <r.fritz@colorcube.de>
 */

define ('T3_ERR_SV_GENERAL', -1); // General error - something went wrong
define ('T3_ERR_SV_NOT_AVAIL', -2); // During execution it showed that the service is not available and should be ignored. The service itself should call $this->setNonAvailable()
define ('T3_ERR_SV_WRONG_SUBTYPE', -3); // passed subtype is not possible with this service
define ('T3_ERR_SV_NO_INPUT', -4); // passed subtype is not possible with this service


define ('T3_ERR_SV_FILE_NOT_FOUND', -20); // File not found which the service should process
define ('T3_ERR_SV_FILE_READ', -21); // File not readable
define ('T3_ERR_SV_FILE_WRITE', -22); // File not writeable

define ('T3_ERR_SV_PROG_NOT_FOUND', -40); // passed subtype is not possible with this service
define ('T3_ERR_SV_PROG_FAILED', -41); // passed subtype is not possible with this service

// define ('T3_ERR_SV_serviceType_myerr, 100); // All errors with prefix T3SV_ERR_serviceType_ and beginning with number 100 are service type dependent error


require_once(PATH_t3lib.'class.t3lib_exec.php');

class t3lib_svbase {

	/**
	 * service description array
	 */
	var $info=array();

	/**
	 * error stack
	 */
	var $error=array();




	 /**
	  * The output content.
	  * That's what the services produced as result.
	  */
	 var $out = '';

	 /**
	  * The file that should be processed.
	  */
	 var $inputFile = '';

	 /**
	  * The content that should be processed.
	  */
	 var $inputContent = '';

	 /**
	  * The type of the input content (or file). Might be the same as the service subtypes.
	  */
	 var $inputType = '';

	 /**
	  * The file where the output should be written to.
	  */
	 var $outputFile = '';

	 


	/***************************************
	 *
	 *	 Get service meta information
	 *
	 ***************************************/


	/**
	 * @return 	array 	service description array
	 */
	function getServiceInfo() {
		return $this->info;
	}


	/**
	 * @return 	string 	service key
	 */
	function getServiceKey() {
		return $this->info['serviceKey'];
	}


	/**
	 * @return 	string 	service title
	 */
	function getServiceTitle() {
		return $this->info['title'];
	}




	/***************************************
	 *
	 *	 Error handling
	 *
	 ***************************************/


	/**
	 * Puts an error on the error stack. Calling without parameter adds a general error.
	 *
	 * @param	string 	error message
	 * @param	string 	error number (see T3_ERR_SV_* constants)
	 * @return 	void
	 */
	function errorPush($errNum=T3_ERR_SV_GENERAL, $errMsg='Unspecified error occured') {
		array_push($this->error, array('nr'=>$errNum, 'msg'=>$errMsg));

		if (is_object($GLOBALS["TT"])) {
			$GLOBALS['TT']->setTSlogMessage($errMsg,2);
		}

	}


	/**
	 * Removes the last error from the error stack.
	 *
	 * @return 	void
	 */
	function errorPull() {
		array_pop($this->error);

		// pop for $GLOBALS['TT']->setTSlogMessage is not supported
	}


	/**
	 * Returns the last error number from the error stack.
	 *
	 * @return 	string 	error number
	 */
	function getLastError() {
		if(count($this->error)) {
			$error = end($this->error);
			return $error['nr'];
		} else {
			return TRUE; // means all is ok - no error
		}
	}


	/**
	 * Returns the last message from the error stack.
	 *
	 * @return 	string 	error message
	 */
	function getLastErrorMsg() {
		if(count($this->error)) {
			$error = end($this->error);
			return $error['msg'];
		} else {
			return '';
		}
	}


	/**
	 * Returns all error messages as array.
	 *
	 * @return 	array 	error messages
	 */
	function getErrorMsgArray() {
		$errArr = array();

		if(count($this->error)) {
			reset($this->error);
			foreach($this->error as $error) {
				$errArr[] = $error['msg'];
			}
		}
		return $errArr;
	}


	/**
	 * Returns the last array from the error stack.
	 *
	 * @return 	array 	error nr and message
	 */
	function getLastErrorArray() {
		return end($this->error);
	}

	/**
	 * Reset the error stack.
	 *
	 * @return 	void
	 */
	function resetErrors() {
		$this->error=array();
	}



	/***************************************
	 *
	 *	 General service functions
	 *
	 ***************************************/



	/**
	 * check the availability of external programs
	 *
	 * @param	string 	comma list of programs 'perl,python,pdftotext'
	 * @return	boolean 	return FALSE if one program was not found
	 */
	function checkExec($progList) {
		$ret = TRUE;

		require_once(PATH_t3lib."class.t3lib_exec.php");

		$progList = t3lib_div::trimExplode(',', $progList, 1);
		foreach($progList as $prog) {
			if (!t3lib_exec::checkCommand($prog)) {
					// program not found
				$this->errorPush('External program not found: '.$prog, T3_ERR_SV_PROG_NOT_FOUND);
				$ret = FALSE;
			}
		}
		return $ret;
	}


	/**
	 * Deactivate the service. Use this if the service fails at runtime and will not be available.
	 *
	 * @return	void
	 */
	function deactivateService() {
		t3lib_extMgm::deactivateService($this->info['serviceType'], $this->info['serviceKey']);
	}


/**
function available()	{
	global $AB,$BE_USER,$LANG,$BACK_PATH,$TCA_DESCR,$TCA,$HTTP_GET_VARS,$HTTP_POST_VARS,$CLIENT,$TYPO3_CONF_VARS;

	// check if the service is available at runtime
	// the sense of this method is that the service might need some more information to check the availablity

	/ *

	$excludeServiceKeys='';
	while (is_object($serviceObj = t3lib_div::makeInstanceService('anyService','', $excludeServiceKeys))) {
		if ($serviceObj->available('some special parm to check availablity')) {
			break;
		}
		$excludeServiceKeys .= ','.$serviceObj->getServiceKey;
		unset($serviceObj);
	}

	* /

	return TRUE;
}
*/


	/***************************************
	 *
	 *	 IO tools
	 *
	 ***************************************/



	/**
	 * Check if a file exists and is readable.
	 *
	 * @param	string 	File name with absolute path.
	 * @return 	string 	File name or FALSE.
	 */
	function checkInputFile ($absFile)	{
		if(@is_file($absFile)) {
			if(@is_readable($absFile)) {
				return $absFile;
			} else {
				$this->errorPush(T3_ERR_SV_FILE_READ, 'File is not readable: '.$absFile);
			}
		} else {
			$this->errorPush(T3_ERR_SV_FILE_NOT_FOUND, 'File not found: '.$absFile);
		}
		return FALSE;
	}


	/**
	 * Read content from a file a file.
	 *
	 * @param	string 	File name to read from.
	 * @param	integer 	Maximum length to read. If empty the whole file will be read.
	 * @return 	string 	$content or FALSE
	 */
	function readFile ($absFile, $length=0)	{
		$out = FALSE;

		if ($this->checkInputFile ($absFile)) {
			if ($fd = fopen ($absFile, 'rb')) {
				$length = intval($length) ? intval($length) : filesize ($absFile);
				$out = fread ($fd, $length);
				fclose ($fd);
			} else {
				$this->errorPush(T3_ERR_SV_FILE_READ, 'Can not read from file: '.$absFile);
			}
		}
		return $out;
	}


	/**
	 * Write content to a file.
	 *
	 * @param	string 	Content to write to the file
	 * @param	string 	File name to write into. If empty a temp file will be created.
	 * @return 	string 	File name or FALSE
	 */
	function writeFile ($content, $absFile='')	{
		$ret = TRUE;

		if (!$absFile) {
			$absFile = tempnam ('', $this->prefixId);
			if(!$absFile) {
				$this->errorPush(T3_ERR_SV_FILE_WRITE, 'Can not create temp file.');
				$ret = FALSE;
			}
		}

		if($absFile) {
			if ($fd = @fopen($absFile,'wb')) {
				@fwrite($fd, $content);
				@fclose($fd);
			} else {
				$this->errorPush(T3_ERR_SV_FILE_WRITE, 'Can not write to file: '.$absFile);
				$ret = FALSE;
			}
		}

		return ($ret ? $absFile : FALSE);
	}




	/***************************************
	 *
	 *	 IO input
	 *
	 ***************************************/


	/**
	 * Set the input content for service processing.
	 *
	 * @param	mixed
	 */
	function setInput ($content, $type='') {
		$this->inputContent = $content;
		$this->inputFile = '';
		$this->inputType = $type;
	}


	/**
	 * Set the input file name for service processing.
	 *
	 * @param	string 	file name
	 */
	function setInputFile ($absFile, $type='') {
		$this->inputContent = '';
		$this->inputFile = $absFile;
		$this->inputType = $type;
	}


	/**
	 * Get the input content.
	 * Will be read from input file if needed.
	 *
	 * @return 	mixed
	 */
	function getInput () {
		if ($this->inputContent=='') {
			$this->inputContent = $this->readFile($this->inputFile);
		}
		return $this->inputContent;
	}


	/**
	 * Get the input file name.
	 * If the content was set by setContent a file will be created.
	 *
	 * @param	string 	File name. If empty a temp file will be created.
	 * @return 	string 	File name or FALSE if no input or file error.
	 */
	function getInputFile ($absFile='') {
		if($this->inputFile) {
			$this->inputFile = $this->checkInputFile($this->inputFile);
		} elseif ($this->inputContent) {
			$this->inputFile = $this->writeFile($this->inputContent, $absFile);
		}
		return $this->inputFile;
	}




	/***************************************
	 *
	 *	 IO output
	 *
	 ***************************************/


	/**
	 * Set the output file name.
	 *
	 * @param	string 	file name
	 */
	function setOutputFile ($absFile) {
		$this->outputFile = $absFile;
	}


	/**
	 * Get the output content.
	 *
	 * @return 	mixed
	 */
	function getOutput () {
		if ($this->outputFile) {
			$this->out = $this->readFile($this->outputFile);
		}
		return $this->out;
	}


	/**
	 * Get the output file name.
	 *
	 * @return 	mixed
	 */
	function getOutputFile ($absFile='') {
		if (!$this->outputFile) {
			$this->outputFile = $this->writeFile($this->out, $absFile);
		}
		return $this->outputFile;
	}




	/***************************************
	 *
	 *	 Service implementation
	 *
	 ***************************************/

	/**
	 * Initialization of the service.
	 *
	 * The class have to do a strict check if the service is available.
	 * example: check if the perl interpreter is available which is needed to run an extern perl script.
	 *
	 * @return 	boolean 	TRUE if the service is available
	 */
	function init()	{
		$this->reset();

			// check for external programs which are defined by $info['exec']
		if (trim($this->info['exec'])) {
			if (!$this->checkExec($this->info['exec'])) {
			}
		}

		return $this->getLastError();
	}


	/**
	 * Resets the service.
	 * Will be called by init(). Should be used before every use if a service instance is used multiple times.
	 *
	 * @return 	void
	 */
	function reset()	{
		$this->resetErrors();
		$this->out = '';
		$this->inputFile = '';
		$this->inputContent = '';
		$this->inputType = '';
		$this->outputFile = '';
	}


	/* every service type has it's own API
	function process($content='', $type='', $conf=array())	{
	}
	*/

}

/**
 // Does not make sense, because this class is always extended by the service classes..
if (defined("TYPO3_MODE") && $TYPO3_CONF_VARS[TYPO3_MODE]["XCLASS"]["t3lib/class.t3lib_svbase.php"])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]["XCLASS"]["t3lib/class.t3lib_svbase.php"]);
}
*/

?>

