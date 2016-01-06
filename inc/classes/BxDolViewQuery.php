<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) BoonEx Pty Limited - http://www.boonex.com/
 * CC-BY License - http://creativecommons.org/licenses/by/3.0/
 *
 * @defgroup    TridentCore Trident Core
 * @{
 */

/**
 * @see BxDolView
 */
class BxDolViewQuery extends BxDolObjectQuery
{
    protected $_iPeriod;

    public function __construct(&$oModule)
    {
        parent::__construct($oModule);

        $aSystem = $this->_oModule->getSystemInfo();
        $this->_iPeriod = (int)$aSystem['period'];
    }

    public function doView($iObjectId, $iAuthorId, $sAuthorIp)
    {
        $iAuthorNip = ip2long($sAuthorIp);

        if($iAuthorId)
            $sWhere = $this->prepare(" AND `viewer_id` = ? ", $iAuthorId);
        else
            $sWhere = $this->prepare(" AND `viewer_id` = '0' AND `viewer_nip` = ? ", $iAuthorNip);

        $sQuery = $this->prepare("SELECT `date` FROM `{$this->_sTableTrack}` WHERE `object_id` = ? $sWhere", $iObjectId);
        $iDate = (int)$this->getOne($sQuery);
        $iDateNow = time();

        if(!$iDate) {
            $sQuery = $this->prepare("INSERT IGNORE INTO `{$this->_sTableTrack}` SET `object_id` = ?, `viewer_id` = ?, `viewer_nip` = ?, `date` = ?", $iObjectId, $iAuthorId, $iAuthorNip, $iDateNow);
            return (int)$this->query($sQuery) > 0;
        }

        if(($iDateNow - $iDate) > $this->_iPeriod) {
            $sQuery = $this->prepare("UPDATE `{$this->_sTableTrack}` SET `date` = ? WHERE `object_id` = ? AND `viewer_id` = ? AND `viewer_nip` = ?", $iDateNow, $iObjectId, $iAuthorId, $iAuthorNip);
            return (int)$this->query($sQuery) > 0;
        }
    }

    public function updateTriggerTable($iObjectId)
    {
        $sQuery = $this->prepare("UPDATE `{$this->_sTriggerTable}` SET `{$this->_sTriggerFieldCount}` = `{$this->_sTriggerFieldCount}` + 1 WHERE `{$this->_sTriggerFieldId}` = ?", $iObjectId);
        return (int)$this->query($sQuery) > 0;
    }
}

/** @} */
