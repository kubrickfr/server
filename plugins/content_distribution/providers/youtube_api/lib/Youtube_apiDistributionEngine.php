<?php
require_once 'Youtube_apiImpl.php';
/**
 * @package plugins.youtube_apiDistribution
 * @subpackage lib
 */
class Youtube_apiDistributionEngine extends DistributionEngine implements 
	IDistributionEngineSubmit,
	IDistributionEngineCloseSubmit, 
	IDistributionEngineUpdate,
	IDistributionEngineDelete,
	IDistributionEngineReport
{
	protected $tempXmlPath;
	
	/* (non-PHPdoc)
	 * @see DistributionEngine::configure()
	 */
	public function configure(KSchedularTaskConfig $taskConfig)
	{
		if($taskConfig->params->tempXmlPath)
		{
			$this->tempXmlPath = $taskConfig->params->tempXmlPath;
			if(!is_dir($this->tempXmlPath))
				mkdir($this->tempXmlPath, 0777, true);
		}
		else
		{
			KalturaLog::err("params.tempXmlPath configuration not supplied");
			$this->tempXmlPath = sys_get_temp_dir();
		}
	}

	/* (non-PHPdoc)
	 * @see IDistributionEngineSubmit::submit()
	 */
	public function submit(KalturaDistributionSubmitJobData $data)
	{
		if(!$data->distributionProfile || !($data->distributionProfile instanceof KalturaYoutube_apiDistributionProfile))
			throw new Exception("Distribution profile must be of type KalturaYoutube_apiDistributionProfile");
	
		return $this->doSubmit($data, $data->distributionProfile);
	}

	protected function newCustomDataElement($title, $value = '')
	{
		$customDataElement = new Youtube_apiCustomDataElement();
		$customDataElement->title = $title;
		$customDataElement->value = $value;
		return $customDataElement;
	}
	
	private function getFlavorFormat($containerFormat)
	{
		$containerFormat = trim(strtolower($containerFormat));
		if(isset(self::$containerFormatMap[$containerFormat]))
			return self::$containerFormatMap[$containerFormat];
			
		return Youtube_apiFormat::_UNKNOWN;
	}
	
	/**
	 * @param KalturaDistributionJobData $data
	 * @param KalturaYoutube_apiDistributionProfile $distributionProfile
	 * @return array()
	 */
	public function getYoutube_apiProps(KalturaBaseEntry $entry, KalturaDistributionJobData $data, KalturaYoutube_apiDistributionProfile $distributionProfile)
	{	
		$entryId = $data->entryDistribution->entryId;
		$entry = $this->kalturaClient->media->get($entryId);
	
//		$metadataObjects = $this->getMetadataObjects($data->entryDistribution->partnerId, $data->entryDistribution->entryId);
		$props = array();
		$props['keywords'] = $entry->tags;
		$props['title'] = $entry->name;
		$props['category'] = $distributionProfile->defaultCategory;
		$props['description'] = $entry->description;
		$props['start_date'] = time();
		$props['end_date'] = time();
		$props['playlists']= $data->providerData->playlists;
		$props['comment']= $distributionProfile->allowComments;
		$props['rate']= $distributionProfile->allowEmbedding;
		$props['commentVote']= $distributionProfile->allowRatings;
		$props['videoRespond']= $distributionProfile->allowResponses;
		

		return $props;
	}
	
	public function doSubmit(KalturaDistributionSubmitJobData $data, KalturaYoutube_apiDistributionProfile $distributionProfile)
	{	
		$needDel = false;
		$entry = $this->getEntry($data->entryDistribution->partnerId, $data->entryDistribution->entryId);
		$props = $this->getYoutube_apiProps($entry, $data, $distributionProfile);
		if($data->entryDistribution->remoteId)
		{
			$youTubeApiImpl = new YouTubeApiImpl($distributionProfile->username, $distributionProfile->password);
			$youTubeApiImpl->update($data->remoteId, $props);
		
			$data->remoteId = $data->entryDistribution->remoteId;
			return true;
		}
			
		$videoFilePath = $data->providerData->videoAssetFilePath;
		
		if (!$videoFilePath)
			throw new KalturaException('No video asset to distribute, the job will fail');
			
		if (!file_exists($videoFilePath))
			throw new KalturaDistributionException('The file ['.$videoFilePath.'] was not found (probably not synced yet), the job will retry');
		
		if (FALSE === strstr($videoFilePath, "."))
		{
			$videoFilePathNew = $this->tempXmlPath . "/" . uniqid() . ".dme";
/*			try
			{
    		KalturaLog::debug("DM : before " . $videoFilePathNew);
    			@symlink ($videoFilePath, $videoFilePathNew);
    		KalturaLog::debug("DM : after");
    		}
    		catch(Exception $ex)
    		{
    		KalturaLog::debug("DM : exception");
    		}*/
			if (!file_exists($videoFilePathNew))
			{
				copy($videoFilePath,$videoFilePathNew);
				$needDel = true;
			}
			$videoFilePath = $videoFilePathNew;
		}
		
		$youTubeApiImpl = new YouTubeApiImpl($distributionProfile->username, $distributionProfile->password);
		$remoteId = $youTubeApiImpl->uploadVideo($videoFilePath,$videoFilePath,$props);
	
		if ($needDel == true)
		{
			unlink($videoFilePath);
		}
		$data->remoteId = $remoteId;
		return true;
	}
	
	/* (non-PHPdoc)
	 * @see IDistributionEngineCloseSubmit::closeSubmit()
	 */
	public function closeSubmit(KalturaDistributionSubmitJobData $data)
	{
		$distributionProfile = $data->distributionProfile;
		$youTubeApiImpl = new YouTubeApiImpl($distributionProfile->username, $distributionProfile->password);
		
		$status = $youTubeApiImpl->getStatus($data->remoteId);
				
		switch($status)
		{
			case 'encoding_error':
				throw new Exception("Youtube_api error encoding");
							
			case 'waiting':
			case 'processing':
			case 'rejected':
				return false;
							
			case 'deleted':
			case 'ready':
			case 'published':
				return true;
		}
	}
	
	/* (non-PHPdoc)
	 * @see IDistributionEngineUpdate::update()
	 */
	public function update(KalturaDistributionUpdateJobData $data)
	{
		if(!$data->distributionProfile || !($data->distributionProfile instanceof KalturaYoutube_apiDistributionProfile))
			throw new Exception("Distribution profile must be of type KalturaYoutube_apiDistributionProfile");
	
		return $this->doUpdate($data, $data->distributionProfile);
	}
	
	public function doUpdate(KalturaDistributionUpdateJobData $data, KalturaYoutube_apiDistributionProfile $distributionProfile)
	{
		$entry = $this->getEntry($data->entryDistribution->partnerId, $data->entryDistribution->entryId);
		$props = $this->getYoutube_apiProps($entry, $data, $distributionProfile);
	
		$youTubeApiImpl = new YouTubeApiImpl($distributionProfile->username, $distributionProfile->password);
		$youTubeApiImpl->updateEntry($data->remoteId, $props);
		
//		$data->sentData = $youtube_apiMediaService->request;
//		$data->results = $youtube_apiMediaService->response;
		
		return true;
	}
	
	/* (non-PHPdoc)
	 * @see IDistributionEngineDelete::delete()
	 */
	public function delete(KalturaDistributionDeleteJobData $data)
	{
		$distributionProfile = $data->distributionProfile;
		$youTubeApiImpl = new YouTubeApiImpl($distributionProfile->username, $distributionProfile->password);
		
		$youTubeApiImpl->deleteEntry($data->remoteId);
		
		return true;
	}
	
	/* (non-PHPdoc)
	 * @see IDistributionEngineReport::fetchReport()
	 */
	public function fetchReport(KalturaDistributionFetchReportJobData $data)
	{
		// TODO
	}
}