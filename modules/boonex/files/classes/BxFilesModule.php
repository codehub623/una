<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) UNA, Inc - https://una.io
 * MIT License - https://opensource.org/licenses/MIT
 *
 * @defgroup    Files Files
 * @ingroup     UnaModules
 *
 * @{
 */

/**
 * Files module
 */
class BxFilesModule extends BxBaseModTextModule
{
    function __construct(&$aModule)
    {
        parent::__construct($aModule);
    }

    public function serviceIsAllowedAddContentToProfile($iGroupProfileId)
    {
        if (!$iGroupProfileId || !($oProfile = BxDolProfile::getInstance((int)$iGroupProfileId)))
            return false;

        if ($iGroupProfileId == bx_get_logged_profile_id())
            return true;

        $sProfileModule = $oProfile->getModule();

        $aContentInfo = BxDolService::call($sProfileModule, 'get_content_info_by_id', array($oProfile->getContentId()));
        if (BxDolService::call($sProfileModule, 'is_group_profile') && (BxDolService::call($sProfileModule, 'is_fan', array($iGroupProfileId)) || bx_get_logged_profile_id() == $aContentInfo['author']))
            return true;

        return false;
    }

    public function serviceMyEntriesActions ($iProfileId = 0)
    {
        if (!$iProfileId)
            $iProfileId = bx_process_input(bx_get('profile_id'), BX_DATA_INT);

        if (!$this->serviceIsAllowedAddContentToProfile($iProfileId))
            return false;

        $oMenu = BxTemplMenu::getObjectInstance($this->_oConfig->CNF['OBJECT_MENU_ACTIONS_MY_ENTRIES']);
        return $oMenu ? $oMenu->getCode() : false;
    }

    public function checkAllowedSetThumb ($iContentId = 0)
    {
        return _t('_sys_txt_access_denied');
    }

    public function actionDownload($sToken, $iContentId, $sBulk = '')
    {
        $CNF = $this->_oConfig->CNF;

        $aFilesToDownload = [$iContentId];
        if (!empty($sBulk)) {
            $aFilesToDownload = json_decode($sBulk);
        }

        if (!is_array($aFilesToDownload)) {
            $this->_oTemplate->displayPageNotFound();
            return;
        }

        $iContentId = reset($aFilesToDownload);

        $aData = $this->_oDb->getContentInfoById((int)$iContentId);
        if (!$aData) {
            $this->_oTemplate->displayPageNotFound();
            return;
        }

        if (CHECK_ACTION_RESULT_ALLOWED !== $this->checkAllowedView($aData)) {
            $this->_oTemplate->displayAccessDenied();
            return;
        }

        if ($aData['type'] == 'folder' || $aFilesToDownload && count($aFilesToDownload) > 1) {
            bx_import('BulkDownloader', $this->_aModule);
            $oDownloader = new BxFilesBulkDownloader($this);
            $aResult = $oDownloader->createDownloadingJob($aFilesToDownload, $aData[$CNF['FIELD_TITLE']]);

            switch ($aResult['status']) {
                case BX_FILES_DOWNLOADER_STATUS_TOO_LARGE:
                    $this->_oTemplate->displayMsg(_t('_bx_files_txt_max_size_reached', _t_format_size(intval(getParam('bx_files_max_bulk_download_size')) * 1024 * 1024)));
                    break;
                case BX_FILES_DOWNLOADER_STATUS_DOWNLOADING:
                    $this->_oTemplate->addJsTranslation('_bx_files_txt_status_downloading_ready_msg');
                    $this->_oTemplate->displayMsg(['title' => _t('_bx_files_txt_status_downloading'), 'content' => $this->_oTemplate->parseHtmlByName('bulk_downloader_process.html', [
                        'timeout' => BX_FILES_DOWNLOADER_TIMEOUT_REQUESTS,
                        'action_url' => BX_DOL_URL_ROOT.$this->_oConfig->getBaseUri().'process_download/'.$aResult['job'],
                    ])]);
                    break;
                case BX_FILES_DOWNLOADER_STATUS_READY:
                    bx_smart_readfile($aResult['file'], $aResult['filename'], 'application/zip');
                    break;
                case BX_FILES_DOWNLOADER_STATUS_EMPTY:
                default:
                    $this->_oTemplate->displayPageNotFound();
                    break;
            }
            exit;
        }

        $aFile = $this->getContentFile($aData);
        if (!$aFile) {
            $this->_oTemplate->displayPageNotFound();
            return;
        }

        $oStorage = BxDolStorage::getObjectInstance($CNF['OBJECT_STORAGE']);
        if (!$oStorage) {
            $this->_oTemplate->displayErrorOccured();
            return;
        }

        if (!$oStorage->download($aFile['remote_id'], $sToken)) {
            $this->_oTemplate->displayErrorOccured();
            return;
        }
        
        exit;   
    }

    public function actionProcessDownload($iDownloadingJobId) {
        if (!$iDownloadingJobId) die;

        $aJob = $this->_oDb->getDownloadingJob($iDownloadingJobId);
        if (!$aJob || $aJob['owner'] != bx_get_logged_profile_id()) die;

        if ($aJob['result']) {
            bx_smart_readfile($aJob['result'], $aJob['name'], 'application/zip');
            exit;
        }

        bx_import('BulkDownloader', $this->_aModule);
        $oDownloader = new BxFilesBulkDownloader($this);

        $mRes = $oDownloader->processDownloading($aJob['files']);
        if ($mRes === BX_FILES_DOWNLOADER_STATUS_DOWNLOADING) {
            $this->_oDb->updateDownloadingJob($iDownloadingJobId, $aJob['files'], '');
            echoJson([
                'status' => 'downloading',
            ]);
        } else {
            $this->_oDb->updateDownloadingJob($iDownloadingJobId, $aJob['files'], $mRes);
            echoJson([
                'status' => 'ready',
            ]);
        }
    }
    
    public function getContentFile($aData) 
    {
        $CNF = $this->_oConfig->CNF;

        if (!isset($aData[$CNF['FIELD_AUTHOR']]) || !isset($aData[$CNF['FIELD_ID']]))
            return false;

        if (isset($aData['type']) && $aData['type'] == 'folder') {
            return [
                'id' => $aData[$CNF['FIELD_ID']],
                'profile_id' => $aData[$CNF['FIELD_AUTHOR']],
                'remote_id' => 0,
                'path' => '',
                'file_name' => $aData[$CNF['FIELD_TITLE']],
                'mime_type' => '',
                'ext' => '',
                'size' => 0,
                'added' => $aData[$CNF['FIELD_ADDED']],
                'modified' => 0,
                'private' => false,
                'is_image' => false,
            ];
        }

        $oStorage = BxDolStorage::getObjectInstance($CNF['OBJECT_STORAGE']);
        if (!$oStorage)
            return false;

        if (isset($aData[$CNF['FIELD_FILE_ID']]) && $aData[$CNF['FIELD_FILE_ID']])
            return $this->_addFileFields($oStorage->getFile($aData[$CNF['FIELD_FILE_ID']]));
        
        $aGhostFiles = $oStorage->getGhosts ($aData[$CNF['FIELD_AUTHOR']], $aData[$CNF['FIELD_ID']]);
        if (!$aGhostFiles)
            return false;

        return $this->_addFileFields(array_pop($aGhostFiles));
    }

    protected function _addFileFields($aFile) 
    {
        if (is_array($aFile))
            $aFile['is_image'] = in_array($aFile['ext'], array('jpg', 'jpeg', 'png', 'gif', /* when ImageMagick is used - 'tif', 'tiff', 'bmp', 'ico', 'psd' */));
        return $aFile;
    }

    public function serviceEntityFilePreview($iContentId = 0)
    {
        return $this->_serviceTemplateFunc ('entryFilePreview', $iContentId);
    }

    public function serviceProcessFilesData($iNumberOfFilesToProcessAtOnce = 3)
    {
        $CNF = $this->_oConfig->CNF;
        
        if (!defined('BX_SYSTEM_JAVA') || !constant('BX_SYSTEM_JAVA'))
            return;
        
        $a = $this->_oDb->getNotProcessedFiles($iNumberOfFilesToProcessAtOnce);
        if (!$a)
            return;

        $oStorage = BxDolStorage::getObjectInstance($CNF['OBJECT_STORAGE']);
        if (!$oStorage)
            return false;
        
        foreach ($a as $aContentInfo) {
            $aFile = $this->getContentFile($aContentInfo);
            if (!$aFile) {
                $this->_oDb->updateFileData ($aContentInfo[$CNF['FIELD_ID']], '');
                continue;
            }

            $sFileUrl = $oStorage->getFileUrlById($aFile['id']);
            $sFilePath = BX_DIRECTORY_PATH_TMP . $aFile['remote_id'] . '.' . $aFile['ext'];
            @file_put_contents($sFilePath, file_get_contents($sFileUrl));
            if (!file_exists($sFilePath)) {
                $this->_oDb->updateFileData ($aContentInfo[$CNF['FIELD_ID']], '');
                continue;
            }
            
            $sCommand = '"' . constant('BX_SYSTEM_JAVA') . '" -jar "' . $this->_oConfig->getHomePath() . 'data/tika-app.jar" --encoding=UTF-8 --text "' . $sFilePath . '"';
            $sData = `$sCommand`;

            @unlink($sFilePath);

            $this->_oDb->updateFileData ($aContentInfo[$CNF['FIELD_ID']], $sData);
        }
    }

    public function servicePruneDownloadingJobs()
    {
        $aFiles = $this->_oDb->deleteOldDownloadingJobs();
        if ($aFiles) foreach ($aFiles as $sFile) @unlink($sFile);
    }

    protected function _getContentForTimelinePost($aEvent, $aContentInfo, $aBrowseParams = array())
    {
        $aResult = parent::_getContentForTimelinePost($aEvent, $aContentInfo, $aBrowseParams);

        $aFile = $this->getContentFile($aContentInfo);
        if (!$aFile['is_image']) {
            $oStorage = BxDolStorage::getObjectInstance($this->_oConfig->CNF['OBJECT_STORAGE']);
            $sIcon = $oStorage ? $oStorage->getFontIconNameByFileName($aFile['file_name']) : 'far file';

            $aResult['raw'] = $this->_oTemplate->parseHtmlByName('timeline_post.html', array(
                'title' => $aResult['title'],
                'title_attr' => bx_html_attribute($aResult['title']),
                'url' => $aResult['url'],
                'icon' => $sIcon,
            ));
            $aResult['title'] = '';
        }

        return $aResult;
    }

    protected function _getImagesForTimelinePost($aEvent, $aContentInfo, $sUrl, $aBrowseParams = array())
    {
        $aFile = $this->getContentFile($aContentInfo);
        if (!$aFile)
            return array();

        if (!$aFile['is_image'])
            return array();

        $sPhotoThumb = '';
        if ($oImagesTranscoder = BxDolTranscoderImage::getObjectInstance($this->_oConfig->CNF['OBJECT_IMAGES_TRANSCODER_GALLERY']))
            $sPhotoThumb = $oImagesTranscoder->getFileUrl($aFile['id']);

        return array(
            array('id' => $aFile['id'], 'url' => $sUrl, 'src' => $sPhotoThumb),
        );
    }

    public function actionBookmark($iContentId) {
        if (!$this->isLogged()) return;

        $aFilesToBookmark = [];
        if ($iContentId) $aFilesToBookmark = [$iContentId];
        elseif (isset($_POST['bulk'])) $aFilesToBookmark = $_POST['bulk'];

        if ($aFilesToBookmark)
            foreach ($aFilesToBookmark as $iContentId) {
                if ($iContentId <= 0) continue;

                $aData = $this->_oDb->getContentInfoById((int)$iContentId);
                if (!$aData) continue;

                $this->_oDb->bookmarkFile((int)$iContentId, bx_get_logged_profile_id());
            }
    }

    public function actionEntryPreview($iContentId) {
        $aData = $this->_oDb->getContentInfoById((int)$iContentId);

        $sJsCss = '';

        $sPreviewCode = $this->_serviceTemplateFunc ('entryFilePreview', $iContentId);

        // various init scripts could be there, which fail to work properly
        // because of a fact that it is inside a popup which is at the moment is hidden
        // so we need a way to postpone that initialization
        $aScripts = [];
        $sPreviewCode = preg_replace_callback('/<script>(.*)<\/script>/s', function($aMatch) use (&$aScripts) {
            $aScripts[] = ['code' => $aMatch[1]];
            return '';
        }, $sPreviewCode);

        // if there is a script then most likely it is a text + codemirror initialization, so include it here.
        if ($aScripts) {
            $sJsCss .= $this->_oTemplate->addJs('codemirror/codemirror-ext.min.js', true);
            $sJsCss .= $this->_oTemplate->addCss(BX_DIRECTORY_PATH_PLUGINS_PUBLIC . 'codemirror/|codemirror.css', true);
        }

        $sJsCss .= $this->_oTemplate->addCss('file_handler.css', true);

        $sPopupCode = $this->_oTemplate->parseHtmlByName('entry-popup.html', [
            'content' => $sPreviewCode,
            'bx_repeat:init_scripts' => $aScripts,
        ]);

        echoJson([
            'popup' => [
                'html' => PopupBox('bx-files-popup', bx_process_output($aData['title']), $sJsCss.$sPopupCode),
                'options' => [
                    'onShow' => 'bx_files_popup_init()',
                ],
            ],
        ]);
    }

    public function actionEntryInfo($iContentId) {
        $aData = $this->_oDb->getContentInfoById((int)$iContentId);

        echoJson([
            'popup' => [
                'html' => PopupBox('bx-files-popup', bx_process_output($aData['title']), $this->_oTemplate->entryInfoPopup($aData)),
            ],
        ]);
    }

    public function actionEntryEditTitle($iContentId) {
        $iContentId = intval($iContentId);
        $aData = $this->_oDb->getContentInfoById($iContentId);

        $CNF = &$this->_oConfig->CNF;

        if (!$aData) {
            echoJson(['message' => _t('_bx_files_txt_error')]);
            exit;
        }

        if ($aData[$CNF['FIELD_AUTHOR']] != bx_get_logged_profile_id()) {
            echoJson(['message' => _t('_bx_files_txt_permission_denied')]);
            exit;
        }

        if (isset($_POST['title'])) {
            if ($sNewTitle = bx_get('title')) {
                $this->_oDb->updateEntryTitle($iContentId, $sNewTitle);
                $aData = $this->_oDb->getContentInfoById($iContentId);
                echo bx_process_output($aData[$CNF['FIELD_TITLE']]);
            }
            exit;
        }

        $sActionUrl = BX_DOL_URL_ROOT.$this->_oConfig->getBaseUri();
        echoJson([
            'eval' => "bx_prompt('"._t('_bx_files_form_entry_input_title')."', '".bx_js_string($aData[$CNF['FIELD_TITLE']])."', function(val) {                
                $.post('{$sActionUrl}entry_edit_title/{$iContentId}', {title: val.getValue()}, function(sTitle) {
                    if (sTitle)
                        $('.bx-file-entry[file_id=".$aData[$CNF['FIELD_ID']]."] .bx-files-filename a').html(sTitle);
                });                
            })",
        ]);
    }

    public function actionEntryDelete($iContentId) {
        $aFilesToDelete = [];
        if ($iContentId) $aFilesToDelete = [$iContentId];
        elseif (isset($_POST['bulk'])) $aFilesToDelete = $_POST['bulk'];

        if ($aFilesToDelete)
            foreach ($aFilesToDelete as $iContentId) {
                $iContentId = intval($iContentId);
                if ($iContentId <= 0) continue;

                $aData = $this->_oDb->getContentInfoById($iContentId);

                $CNF = &$this->_oConfig->CNF;

                if (!$aData) {
                    echoJson(['message' => _t('_bx_files_txt_error')]);
                    exit;
                }

                if ($aData[$CNF['FIELD_AUTHOR']] != bx_get_logged_profile_id() && !($aData[$CNF['FIELD_ALLOW_VIEW_TO']] < 0 && $this->serviceIsAllowedAddContentToProfile(-$aData[$CNF['FIELD_ALLOW_VIEW_TO']]))) {
                    echoJson(['message' => _t('_bx_files_txt_permission_denied')]);
                    exit;
                }

                $this->serviceDeleteEntity($iContentId);
            }

        if (!isset($_POST['bulk'])) {
            echoJson([
                'eval' => "
                    $('.bx-file-entry[file_id={$iContentId}]').fadeOut();
                    $(window).trigger('files_browser.update');
                ",
            ]);
        } else {
            echoJson([]);
        }
    }

    public function actionClearGhosts() {
        if (!isLogged()) die;

        $oStorage = BxDolStorage::getObjectInstance($this->_oConfig->CNF['OBJECT_STORAGE']);
        if ($oStorage) {
            $aFiles = $oStorage->getGhosts(bx_get_logged_profile_id(), 0);

            if ($aFiles) foreach ($aFiles as $aFile) {
                $oStorage->deleteFile($aFile['id']);
            }
        }
    }

    public function actionUploadCompleted() {
        $iContext = intval(bx_get('context'));
        $iFolder = intval(bx_get('folder'));

        if (!isLogged()) {
            echoJson(['message' => _t('_Access denied')]);
            exit;
        }

        if ($this->checkAllowedAdd() != CHECK_ACTION_RESULT_ALLOWED || !$this->serviceIsAllowedAddContentToProfile($iContext)) {
            echoJson(['message' => _t('_Access denied')]);
            exit;
        }

        $oStorage = BxDolStorage::getObjectInstance($this->_oConfig->CNF['OBJECT_STORAGE']);
        if ($oStorage) {
            $iAuthorProfile = bx_get_logged_profile_id();
            $aFiles = $oStorage->getGhosts($iAuthorProfile, 0);

            if ($aFiles) {
                $aFilesIDs = [];
                foreach($aFiles as $aFile) {
                    $aFilesIDs[] = $aFile['id'];
                    bx_set('title-'.$aFile['id'], $aFile['file_name'], 'post');
                }
                bx_set('attachments', $aFilesIDs, 'post');

                $oForm = BxDolForm::getObjectInstance($this->_oConfig->CNF['OBJECT_FORM_ENTRY_UPLOAD'], $this->_oConfig->CNF['OBJECT_FORM_ENTRY_DISPLAY_UPLOAD']);
                $oForm->aInputs['profile_id']['value'] = $iAuthorProfile;
                $oForm->aInputs['allow_view_to']['value'] = $iContext == $iAuthorProfile ? 3 : -$iContext;

                $aFiles = $oForm->insert();

                if ($iFolder != 0 && !empty($aFiles))
                    $this->_oDb->moveFilesToFolder($aFiles, $iFolder);
            }
        }
    }

    public function actionCreateFolder() {
        $iContext = intval(bx_get('context'));
        $iFolder = intval(bx_get('current_folder'));
        $sName = trim(bx_get('name'));

        if (!isLogged()) {
            echoJson(['message' => _t('_Access denied')]);
            exit;
        }

        if ($this->checkAllowedAdd() != CHECK_ACTION_RESULT_ALLOWED || !$this->serviceIsAllowedAddContentToProfile($iContext)) {
            echoJson(['message' => _t('_Access denied')]);
            exit;
        }

        if (!empty($sName)) {
            $iAuthor = bx_get_logged_profile_id();
            //in case it is posted to context make the context profile as an author of an entry
            $this->_oDb->createFolder($iFolder, $iAuthor == $iContext ? $iAuthor : $iContext, $iAuthor == $iContext ? BX_DOL_PG_ALL : -$iContext, $sName);
        }

        echoJson([]);
        exit;
    }

    public function actionMoveFiles() {
        $iContentId = intval(bx_get('file'));

        $aFilesToMove = [];
        if ($iContentId) $aFilesToMove = [$iContentId];
        elseif (isset($_POST['bulk'])) $aFilesToMove = $_POST['bulk'];

        $iContentId = reset($aFilesToMove);

        $aData = $this->_oDb->getContentInfoById($iContentId);

        if (!$aData) {
            echoJson(['message' => _t('_bx_files_txt_error')]);
            exit;
        }

        $CNF = &$this->_oConfig->CNF;

        $iContext = $aData[$CNF['FIELD_ALLOW_VIEW_TO']] < 0 ? -$aData[$CNF['FIELD_ALLOW_VIEW_TO']] : $aData[$CNF['FIELD_AUTHOR']];

        if ($this->checkAllowedAdd() != CHECK_ACTION_RESULT_ALLOWED || !$this->serviceIsAllowedAddContentToProfile($iContext)) {
            echoJson(['message' => _t('_Access denied')]);
            exit;
        }

        if (isset($_POST['move_to'])) {
            $iMoveTo = intval(bx_get('move_to'));
            if ($iMoveTo) {
                $aDataMoveTo = $this->_oDb->getContentInfoById($iMoveTo);
                if (!$aDataMoveTo) {
                    echoJson(['message' => _t('_bx_files_txt_error')]);
                    exit;
                }

                $iContextMoveTo = $aDataMoveTo[$CNF['FIELD_ALLOW_VIEW_TO']] < 0 ? -$aDataMoveTo[$CNF['FIELD_ALLOW_VIEW_TO']] : $aDataMoveTo[$CNF['FIELD_AUTHOR']];

                if ($this->checkAllowedAdd() != CHECK_ACTION_RESULT_ALLOWED || !$this->serviceIsAllowedAddContentToProfile($iContextMoveTo)) {
                    echoJson(['message' => _t('_Access denied')]);
                    exit;
                }
            }

            $this->_oDb->moveFilesToFolder($aFilesToMove, $iMoveTo);

            echoJson([
                'eval' => "                        
                    $(window).trigger('files_browser.update');
                ",
            ]);
            exit;
        }

        $aFolders = $this->_oDb->getFoldersInContext($iContext);
        if (!$aFolders) {
            echoJson(['message' => _t('_bx_files_txt_move_to_nowhere')]);
            exit;
        }

        echoJson([
            'popup' => [
                'html' => PopupBox('bx-files-popup', _t('_bx_files_txt_move_to'), $this->_oTemplate->getJsTree($aFilesToMove, $aFolders)),
                'options' => [],
            ],
        ]);
    }

    public function serviceDeleteEntitiesByAuthor ($iProfileId) {
        $this->_oDb->deleteProfileBookmarks($iProfileId);
        return parent::serviceDeleteEntitiesByAuthor ($iProfileId);
    }

    public function serviceDeleteEntity ($iContentId, $sFuncDelete = 'deleteData') {
        $aData = $this->_oDb->getContentInfoById($iContentId);
        if ($aData['type'] == 'folder') {
            $aNestedFiles = $this->_oDb->getFolderFiles($aData[$this->_oConfig->CNF['FIELD_ID']]);
            if ($aNestedFiles)
                foreach ($aNestedFiles as $iFile) {
                    $this->serviceDeleteEntity($iFile);
                }
        }
        parent::serviceDeleteEntity($iContentId, $sFuncDelete);
    }
}

/** @} */
