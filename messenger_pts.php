<?php
require_once( SDSP_PATH_lib . 'sds_data_objects/messengersubmit.php');
require_once( SDSP_PATH_lib . 'sds_data_objects/sdsdataobject.php');

class Messenger_Pts extends Messenger implements messengerSubmit
{
    protected $_sDatetimeError = '';
    protected $_sAPI = 'http://92.204.133.27/pts_data/api.php';
    protected $oPtsConn;
    private $_err;
    protected $_sUsername = 'sds_user';
    protected $_sPassword = 'UeXV5z8WHhecA9JR';
    protected $_sclientId = 'sds_messenger';

	public function __construct($sName = null)
	{
		parent::__construct($sName);
		//$this->oPtsConn = $this->_createSoapClient(); //set connection once and use it multiple times
	}
    /**
	*
	* @param integer $iLogid
	* @param integer $iTimestampFrom
	* @param integer $iTimestampTo
	* @return true on success, false otherwise
	*/
	public function sendReport($iLogid, $iTimestampFrom, $iTimestampTo)
    {
		//See if we are between 1st and 5th of the month and if we have already reported for this loger ggc
		if($this->checkReportingTimeframeAndStatus())
		{
			$aDates = $this->getDates($iLogid);//Get all dates (for all the days) we need the value ggc
			if($aDates['device'] == 'SL')
			{
				//is SL
				$sum = 0;
				foreach($aDates['dates'] as $from=>$to)
				{
					$aLastDayContent = $this->getData($iLogid, $from, $to);
					if(empty($aLastDayContent))
					{
						//No data for this day, Skip it.
						continue;
					}
					$sum += $this->_getVal($aLastDayContent);
				}

				/**
                                   * NOTE: These functions in the comment are not available for use anymore. Contact with
                                   *       PTS has provided no answer for this issue. All Regular loggers are disabled from
                                   *       automatic reporting until we have a fix for this ourselves.
                                   * @TODO Keep track of the values submited each month to calculate/stimulate a total meter counter
                                   * //Get last meter date
                                   * $aLastMeterDate = $this->_getLastMeterDate($this->getMessengerMember()->systemid);
                                   * $aLastMeterDate = $aLastMeterDate['GetLastMeterDateResult'];
                                   * $aYearMonthDay = explode(' ',$aLastMeterDate);
                                   * $aYearMonthDay = explode('-',$aYearMonthDay[0]);
                                   * //Get previous value
                                   * $previousVal = $this->_getLastMeterValue($this->getMessengerMember()->systemid,$aYearMonthDay[0],$aYearMonthDay[1],$aYearMonthDay[2]);
                                   * $previousVal = $previousVal['GetMeterValueResult'];
                                   */
 
                                  /**
                                   * We are not supposed to get here, but just in case we initialize $previousVal with 0 to trigger a value to small SOAP error
                                   */


		$previousVal = 0;
                //Calculate result and commit
                $iMonthTotal = intval(($sum/1000) + $previousVal);
                //Check if $iMonthTotal is empty,0, or same as the previous, log error and return
                if(empty($iMonthTotal) || $iMonthTotal == 0 || $iMonthTotal == $previousVal)
                {
                    $sMessage = 'SL: Total 0 or the same as previous, Date: '.date('Y-m-d H:i:s').', SN: '.$this->getMessengerMember()->serialnumber.', SysID: '.$this->getMessengerMember()->systemid;
		            $this->gblog($sMessage,SDS_ERROR);
                    return false;
                }
				return $this->commit(array('monthTotal'=>$iMonthTotal));
			}
			elseif($aDates['device'] == 'GE')
			{
                //is GE
                //look for an interval if there is data not just the last record
				$aAllDatesValues = array();
				$iVal = '0';
				//Reverse dates
				$aDatesReverse = array_reverse($aDates['dates'],true);
                foreach($aDatesReverse as $from=>$to)
				{
					$aLastDayContent = $this->getData($iLogid, $from, $to);
					if(empty($aLastDayContent))
					{
						//No data for this day, Skip it.
						continue;
                    }
					$iVal = $this->_getVal($aLastDayContent);
					break;
				}
				$iMonthTotal = intval(($iVal/10)/1000);
                //Check if $iMonthTotal is empty,0, or same as the previous, log error and return
                if(empty($iMonthTotal) || $iMonthTotal == 0)
                {
                    $sMessage = 'GE: Total 0, Date: '.date('Y-m-d H:i:s').', SN: '.$this->getMessengerMember()->serialnumber.', SysID: '.$this->getMessengerMember()->systemid;
		            $this->gblog($sMessage,SDS_ERROR);
                    return false;
                }
				return $this->commit(array('monthTotal'=>$iMonthTotal));
			}
		}//end if checkReportingTimeframeAndStatus
		$sMessage = 'Not between the reporting timeframe, Date: '.date('Y-m-d H:i:s').', SysID: '.$this->getMessengerMember()->systemid;
		$this->gblog($sMessage,SDS_ERROR);
		return false;
	}//end function sendReport($iLogid, $iTimestampFrom, $iTimestampTo)

	/**
	 * Get the value from the last record
	 * @param array $aLastDayContent
	 * @return int value
	 */
	private function _getVal($aLastDayContent)
	{
		end($aLastDayContent['aData']);
		$sKeyLastEntry = key($aLastDayContent['aData']);//key is a formated datetime ggc
		$iLastEntry = (int)$aLastDayContent['aData'][$sKeyLastEntry][$aLastDayContent['iWrid']][$aLastDayContent['iChannel']];
		return $iLastEntry;
	}//end function _getVal

	/**
	 *Check if we are in the timeframe for reporting and if we have not reported for this device
	 *
	 *@return boolean true/false
	 */
	function checkReportingTimeframeAndStatus()
	{
		$fd = date("Y-m-d", mktime(0, 0, 0, date("m"), 1, date("Y")));//first day of current month
		$oDate = new DateTime($fd);//get the first date of the month based on time ggc
		$oDate->setTime(0,0,0);
		$firstDay = $oDate->format('Y-m-d H:i:s');

		$oDate->setTime(23,59,59);
		$oDate->modify('+15 days');
		$lastDay = $oDate->format('Y-m-d H:i:s');

		$bTimeStatus = false;

		//See if the time now is between the first and the fifth of the month
		if(time() >= strtotime($firstDay) && time() <= strtotime($lastDay))
		{
			if(strtotime($this->getMessengerMember()->lasttransfer) < strtotime($firstDay))
			{
				//Log info, Reporting for this plant
				$sMessage = "Reporting for: SN: ".$this->getMessengerMember()->serialnumber.', Logid: '.$this->getMessengerMember()->logid.", Lasttransfer: ".$this->getMessengerMember()->lasttransfer;
				$this->gblog($sMessage, SDS_INFO);
				$bTimeStatus = true;
			}
			else
			{
				//Log info, Already reported for this plant
				$sMessage = "Already reported for: SN: ".$this->getMessengerMember()->serialnumber.", Lasttransfer: ".$this->getMessengerMember()->lasttransfer;
				$this->gblog($sMessage, SDS_ERROR);
				$bTimeStatus = false;
			}
			return $bTimeStatus;
		}
		//Log info, Not within the reporting range
        $sMessage = 'Not within reporting timeframe, Date is: '.date('Y-m-d');
        $this->gblog($sMessage,SDS_ERROR);
		return $bTimeStatus;
	}//end function checkReportingTimeframeAndStatus

	/**
	 * Gets the dates we need to get the data from SL's and GE's
	 *
	 * @param $iLogid
	 * @return assoc array
	 */
    public function getDates($iLogid)
	{
		$aDatesFromTo = array();
		$fd = date("Y-m-d", mktime(0, 0, 0, date("m")-1, 1, date("Y")));//first day previous month
		$ld = date("Y-m-d", mktime(0, 0, 0, date("m"), 0, date("Y")));//last day previous month
		$oFirstDayPrevMonth = new DateTime($fd);
		$oFirstDayPrevMonth->setTime(0,0,0);

		$oLastDayPrevMonth = new DateTime($ld);
		$oLastDayPrevMonth->setTime(23,59,59);

		$oCustomdevice = new Customdevice($iLogid);
		if($oCustomdevice->isGEMeter())
		{
			//is GE
            $oFirstDayPrevMonth->modify("+20 day");//start from 21st
			while(strtotime($oFirstDayPrevMonth->format('Y-m-d H:i:s')) <= strtotime($oLastDayPrevMonth->format('Y-m-d H:i:s')))
			{
				$iFromDate = strtotime($oFirstDayPrevMonth->format('Y-m-d H:i:s'));
				$oFirstDayPrevMonth->modify("+1 day -1 second");
				$iToDate = strtotime($oFirstDayPrevMonth->format('Y-m-d H:i:s'));
				$sDatesFromTo[$iFromDate] = $iToDate;
				$oFirstDayPrevMonth->modify("+1 day");
				$oFirstDayPrevMonth->setTime(0,0,0);
			}
			return array('device'=>'GE','dates'=>$sDatesFromTo);
		}
		else
		{
			//is SL
			while(strtotime($oFirstDayPrevMonth->format('Y-m-d H:i:s')) <= strtotime($oLastDayPrevMonth->format('Y-m-d H:i:s')))
			{
				$iFromDate = strtotime($oFirstDayPrevMonth->format('Y-m-d H:i:s'));
				$oFirstDayPrevMonth->modify("+1 day -1 second");
				$iToDate = strtotime($oFirstDayPrevMonth->format('Y-m-d H:i:s'));
				$sDatesFromTo[$iFromDate] = $iToDate;
				$oFirstDayPrevMonth->modify("+1 day");
				$oFirstDayPrevMonth->setTime(0,0,0);
			}
			return array('device'=>'SL','dates'=>$sDatesFromTo);
		}
	}//end function getDates

	/**
	*Log error status in the database
	*
	*@param string $sDate
	*@param string $sSerialnumber
    *@param boolean $noData
    *@param string $description
	*/
	private function _log_error($sDate, $sSerialnumber, $noData=false, $description='')
	{
		$oDate = new DateTime($sDate);
		if($noData)
		{
			$oDate->setTime(12,00,00);//Set time to noon to avoid skipping to early ggc
		}
		$sDatetimeError = $oDate->format('Y-m-d H:i:s');
		$this->_sDatetimeError = $sDatetimeError;//set the dateTime error here ggc
		$formatDate = $oDate->format('Y-m-d');//Format dateTime again for the database (date only) ggc
		$sQry = "INSERT IGNORE INTO `messenger_log`(`serial_number`,`error_date`,`status`,`description`) VALUES('$sSerialnumber','$formatDate','error','$description')";
		$result = $GLOBALS['SDSP_DATA']->sqlQuery($sQry,false);
	}

	/**
	*Change error status in the database if data is available after an error ocurred
	*/
	private function _fixed_status_messenger_error()
	{
		$date = new DateTime();
		//$date->modify('-1 day');
		$formatDate = $date->format('Y-m-d');
		$sSerialNumber = $this->getMessengerMember()->serialnumber;
		$sQry = "UPDATE `messenger_log`
				 SET status='fixed' WHERE `error_date`='$formatDate'
				 AND `serial_number`='$sSerialNumber'";
		$result = $GLOBALS['SDSP_DATA']->sqlQuery($sQry,false);
	}

	/**
	 *Get the RGM from Solar Logs not GE Meters
	 *
	 *@param int $iLogId
	 *@return object $oRow
	 */
	private function _getRGM($iLogId)
	{
		$sQry	= "SELECT * FROM `wechselrichter` WHERE logid='$iLogId' AND `wrfunc`=2 AND `wrstromz`=1 AND `enabled`=1 AND `deleted`=0";
		$rRes	= $GLOBALS['SDSP_DATA']->sqlQuery($sQry,false);
		$iCount	= $GLOBALS['SDSP_DATA']->sql_num_rows($rRes);
		if($iCount > 1)
		{
            //Log error here
            $sMessage = 'More than one meter selected from database. Must be only one. Number of meters: '.$iCount;
            $this->gblog($sMessage,SDS_ERROR);
			return null;
		}
		$oRow	= $GLOBALS['SDSP_DATA']->sql_fetch_object( $rRes );
		return $oRow;
	}//end function _getRGM($iLogId)

	/**
	*Simple check for data validity, if the day is not complete for a specific date we assume
	*that the data is not correct, this day has to be reported again.
	*
	*@param array $aData
	*@param int $iWrid
	*@param int iChannel
	*@param string $sSerialnumber
	*@param string formated DatetimeFrom
	*/
	public function isDataValid($aData, $iWrid, $iChannel, $sSerialnumber,$sDatetimeFrom)
	{
		//Nothing to check if there is no data, log errors and return imediately ggc
		if(!$aData)
		{
			$description = 'No data for this date';
			$this->_log_error($sDatetimeFrom, $sSerialnumber, true, $description);//log info in the database ggc
			return false;
        }
		end($aData);//last element
		$sKey = key($aData);//key is a formated datetime ggc
		$aLast = $aData[$sKey];
		$sTime = date('H', strtotime($sKey));//extract time from key ggc
		//See if both channels we need are set then proceed
		if(isset($aLast[$iWrid][$iChannel]))
		{
			return true;
		}
		$description = 'iChannel not set, see channels configuration';
		$this->_log_error($sKey, $sSerialnumber,false,$description);//log info in the database
		$this->gblog('iChannel not set, see channels configuration, ' . 'Data not valid for plant with serialnumber: ' . $sSerialnumber . ', Datetime: ' . $sDatetimeFrom , SDS_ERROR);
		return false;
	}//end of isDataValid($aData, $iWrid, $iChannel, $iPACChannel, $sSerialnumber,$sDatetimeFrom)


    /**
	*Get min data for a device.
	*
	*@param int $iLogid
	*@param int $iTimestampFrom
	*@param int $iTimestampTo
    *@return string on success, empty array on failure
	*/
	public function getData($iLogid, $iTimestampFrom, $iTimestampTo)
	{
		$aResult = array();
		$bIsGEMeter = false;
		$oCustomdevice = new Customdevice($iLogid);
		if($oCustomdevice->isGEMeter())
		{
			$bIsGEMeter = true;
			$iWrid = $this->_getDataHelper_iWrid($oCustomdevice);
			$iChannel = CH::getChannel(CH::ETOTAL_C, 2); // total production energy count ggc
		}
		else
		{
			$oRow = $this->_getRGM($oCustomdevice->id);
			if($oRow != null)
			{
				$iWrid = $oRow->wrid;
			}
			else
			{
				//Log Error, and deal with it. -1 is not a acceptable wrid.
                $iWrid = -1;
                $sMessage = 'getData set iWrid to -1, No RGM or more than one RGM present. iWrid is: '.$iWrid;
                $this->gblog($sMessage, SDS_ERROR);
			}
			$iChannel = CH::getChannel(CH::ETOTAL, 0); // total production energy count ggc
		}

		$sDatetime = date('Y-m-d H:i:s', $iTimestampFrom);
		$sDatetimeFrom = date('Y-m-d H:i:s', $iTimestampFrom);
		$sDatetimeTo = date('Y-m-d H:i:s', $iTimestampTo);

		$sSerialnumber = $oCustomdevice->serialnumber;
		$sTimezone = $oCustomdevice->getTimezone();
		$aDevices = $oCustomdevice->getDevices('Min',$sDatetime);
		$aData = $oCustomdevice->getMinuteData($sDatetime, $sDatetimeFrom, $sDatetimeTo);
		//Check if data is valid, and return array with $iWrid,$iChannel,$aData ggc
		if($this->isDataValid($aData, $iWrid, $iChannel, $sSerialnumber,$sDatetimeFrom))
		{
			return array('iWrid'=>$iWrid,'iChannel'=>$iChannel,'isGEMeter'=>$bIsGEMeter,'aData'=>$aData);
		}
		//Data not valid here, return empty array ggc
		return array();
	}//end getData($iLogid, $sDateTimeFrom, $sDateTimeTo)

	/**
	 * Returns $iWrid value based on the device type and CRC version number
	 *
	 * @param $oCustomdevice
	 * @return int $iWrid
	 */
	private function _getDataHelper_iWrid($oCustomdevice)
	{
		$iWrid = 1;
		if($oCustomdevice->device == self::SOLARLOG_350)//SL 350 ggc
		{
			if($oCustomdevice->importversion == 101)
			{
				$iWrid = 1;
			}
			else
			{
				$iWrid = 2;
			}
		}
		elseif($oCustomdevice->device == self::SOLARLOG_360)//SL 360 ggc
		{
			if($oCustomdevice->importversion == 99)
			{
				$iWrid = 3;
			}
			else
			{
				$iWrid = 1;
			}
		}
		elseif($oCustomdevice->device == self::SOLARLOG_370)// SL 370 ggc
		{
			$iWrid = 1;
		}
		return $iWrid;
	}//end

	/**
	*When data is commited and it is successful, finalize the commit, update the last successful commit date
	*@param boolean $bSuccessfull
	*/
	public function post_committing($bSuccessfull)
	{
		if($bSuccessfull === true)
        {
            if($bSuccessfull === true && $this->_sDatetimeError == MessengerMembers::NOERROROCURRED)
            {
                //Log error to file for errors to have the info
			}
			$this->_fixed_status_messenger_error();
            //Update last transfer to the day the data was reported
			$this->getMessengerMember()->updateLastTransfer();
			// well done
			// @todo: do logging here
			return $bSuccessfull;
		}
		elseif($bSuccessfull === false)
        {
            if($bSuccessfull === false && $this->_sDatetimeError == MessengerMembers::NOERROROCURRED)
            {
                //@todo Log error to file for errors to have the info
            }

			$sDescription='Monthly data to Pts could not be submited,see log file';
			$dDateError = date('Y-m-d');
			$sSerialnumber = $this->getMessengerMember()->serialnumber;
			$this->_log_error($dDateError, $sSerialnumber,false,$sDescription);//log info in the database
			return $bSuccessfull;
		}
    }//end of function post_commiting($bSuccessfull) ggc

    /**
     * errorHandler
     *
     * Error handling for SOAP messages
     *
     * @param: $errno
     * @param: $errstr
     * @param: $errfile
     * @param: errline
     *
	 */
	public function errorHandler($errno, $errstr, $errfile, $errline)
	{
		switch ($errno)
		{
			case E_NOTICE:
			case E_USER_NOTICE:
			case E_STRICT:
				//echo("STRICT error $errstr at $errfile:$errline \n");
				$this->_err =  "STRICT error " . $errstr ." at ". $errfile ." : ".$errline;
				break;
			case E_WARNING:
			case E_USER_WARNING:
				//echo("WARNING error $errstr at $errfile:$errline \n")
				$this->_err = "STRICT error " . $errstr ." at ". $errfile ." : ".$errline;
				break;
			case E_ERROR:
			case E_USER_ERROR:
				exit("FATAL error $errstr at $errfile:$errline \n");
			default:
				exit("Unknown error at $errfile:$errline \n");
		}
	}

	/**
  *Submit data to server
  *@param assoc array $mData
  *@param Boolean $bSuccessfull
  */
  public function dataSubmit($mData,&$bSuccessfull)
    {


    set_error_handler(array($this, "errorHandler"));
    $reportMsg = $this->_postData($this->getMessengerMember()->systemid,$mData['monthTotal'],date('Y-m-d\TH:i:s'));//uncomment for commit
        //$XMLResponseValues = simplexml_load_string($reportMsg['postdataResult']);//uncoment for commit

    //IMPORTANT, NOT FOR PRODUCTION, TESTING REPORTING ONLY
    //$reportMsg = $this->_testPostData($this->getMessengerMember()->systemid,$mData['monthTotal'],date('Y-m-d\TH:i:s'));
    //$XMLResponseValues = simplexml_load_string($reportMsg['testpostdataResult']);
    //IMPORTANT, NOT FOR PRODUCTION, TESTING REPORTING ONLY

    //$sSuccess = (string) $XMLResponseValues->Results->ARSystemResults->Success;
    $sMonthlyValSent = $mData['monthTotal'];
    //$sSystemID = (string) $XMLResponseValues->Results->ARSystemResults->attributes()->SystemID;
        if($this->_err == null)
    {
        if($reportMsg === 'true')
        {
          //Log success to file ggc
          $sMessage = "SN: ".$this->getMessengerMember()->serialnumber.
                ", Success: ".$reportMsg.
                ", SystemID: ".$this->getMessengerMember()->systemid.
                ", Monthly value sent: ".$sMonthlyValSent;
          $this->gblog($sMessage, SDS_INFO);
          $bSuccessfull = true;
        }
        elseif($reportMsg === 'false')
        {
          //Log error to file ggc
          $sMessage = "SN: ".$this->getMessengerMember()->serialnumber.
                ", Success: ".$reportMsg.
                ", SystemID: ".$this->getMessengerMember()->systemid.
                ", Monthly value sent: ".$sMonthlyValSent;
          $this->gblog($sMessage, SDS_ERROR);
          $bSuccessfull = false;
            }
        }
        else
        {
            
            $sMessage = $this->_err;
            $this->gblog($sMessage, SDS_ERROR);
      $bSuccessfull = false;
        }
  }//end function dataSubmit

  /**
  *Logging for PTS:
  *@param string $sMessage
  *@param $iLogLevel
  */
  protected function gblog($sMessage, $iLogLevel=SDS_INFO)
  {
    sdslog('messenger_pts', $sMessage, $iLogLevel);
  }


  /**
  *Posting Data to Iplon Cloud:
  *@param string $sPTSSysId
  *@param $kwhRegistry
  *@param $regDateTime
  * @return string $response
  */
  private function _postData($sPTSSysId,$kwhRegistry,$regDateTime)
  {
     $postData = '';
     
     $data = array('fc'=>'ptsData',
                   'systemId'=>$sPTSSysId,
                   'metervalue'=>$kwhRegistry,
                   'meterDate'=>$regDateTime,
               	   'username'=>$this->_sUsername,
               	   'password'=>$this->_sPassword,
               	   'client_id'=>$this->_sclientId);
     $postData = http_build_query($data, '', '&');

     $ch = curl_init( $this->_sAPI );
     curl_setopt( $ch, CURLOPT_POST, 1);
     curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
     curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
     curl_setopt($ch, CURLOPT_POST, count($postData));
     curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
     $output = curl_exec($ch);  
     $this->gblog("Testing in _postData function".$output, SDS_ERROR);
     curl_close($ch);
     
     return $response = curl_exec( $ch );
  }//end function _postData($sPTSSysId,$kwhRegistry,$regDateTime)


}//end class Messenger_Pts
?>