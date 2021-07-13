<?php
/*
 * This file is part of the SolarLog-Web an SolarLog-Portal software.
 *
 * @author Solare Datensysteme GmbH <info@solar-log.com>
 * @copyright (c) Solare Datensysteme GmbH 2008-2011
 * @license
 * @version 0.1 Experimental
 * @since
 * @link http://www.solar-log.com
 */
require_once( SDSP_PATH_lib . 'sds_data_objects/sdsdataobject.php');
/**
 *
 */
class Messenger extends sdsDataObject
{
    protected $sTablename = 'messenger';
	private $_oMessengerMember = null;

	const SOLARLOG_350 = 14;
	const SOLARLOG_360 = 15;
	const SOLARLOG_370 = 16;

    /**
     * constructs the data object for $this->sTablename
     * and load data by id if given
     *
     * @see sdsDataObject::__construct
     * @param int $iId
     */
    public function __construct( $sName = null )
    {
        parent::__construct( $sName );
    }

	public function report($iLogid, $iTimestampFrom, $iTimestampTo)
	{
		if($this->bExists() && $this->enabled)
		{
			// create a messenger service/agent object depending on sql.messenger.name
			$sClassname = strtolower(get_class($this).'_'.$this->name); // e.g. Messenger_GreenBank
			$oMessengerService = new $sClassname($this->name); // e.g. new Messenger_GreenBank('GreenBank')
			return $oMessengerService->setMessengerMember($this->getMessengerMember())
									 ->sendReport($iLogid, $iTimestampFrom, $iTimestampTo);
		}
		return false;
	}

	/**
	*Set MessengerMembers
	*@param object $oMessengerMember
	*/
	public function setMessengerMember(MessengerMembers $oMessengerMember)
	{
		if($oMessengerMember instanceof MessengerMembers)
		{
			$this->_oMessengerMember = $oMessengerMember;
		}
		return $this;
	}

	/**
	*Get MessengerMembers
	*/
	public function getMessengerMember()
	{
		return $this->_oMessengerMember;
	}

	/**
	 * Submits the data
	 *
	 * @param mixed data $mData
	 */
	public function commit($mData)
    {
		$bSuccessfull = false;
		// open a connection with the given type and send the data
		switch($this->connection_type)
		{
			case 'FTP':
			case 'SFTP':
				$this->dataSubmit($mData,$bSuccessfull);
                break;
            case 'SOAP':
				$this->dataSubmit($mData,$bSuccessfull);
                break;
            case 'API':
                $this->dataSubmit($mData,$bSuccessfull);
                break;
			default:
				break;
		}
		// if(method_exists($this, 'post_committing'))
		// {
			// return $this->post_committing($bSuccessfull);
		// }
		return $this->post_committing($bSuccessfull);
	}//end function commit($aNameData)

	// @todo: implement interface for that...
	// abstract public function sendReport($oMessengerMember, $iLogid, $iTimestampFrom, $iTimestampTo);
	// abstract public function getData($iLogid, $iTimestampFrom, $iTimestampTo);
	// abstract public function post_committing($bSuccessfull);

    // @todo check how this has to be implemented... child classes have to be checked too
    public function exportToFile($sLevel, $iAlternativeId = null, $sAdditionalField=''){}
    public function recreate($aSourceInfo = array(), $aOverrideValues = null, $bUpdate = false){}
}

?>