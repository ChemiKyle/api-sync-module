<?php
namespace Vanderbilt\APISyncExternalModule;

use Exception;

class APISyncExternalModule extends \ExternalModules\AbstractExternalModule{
	function cron(){
		$originalPid = $_GET['pid'];

		foreach($this->framework->getProjectsWithModuleEnabled() as $localProjectId){
			// This automatically associates all log statements with this project.
			$_GET['pid'] = $localProjectId;

			$servers = $this->getSubSettings('servers', $localProjectId);
			foreach($servers as $server){
				if(!$this->isTimeToRun($server)){
					continue;
				}

				$url = $server['redcap-url'];

				// This log mainly exists to show that the sync process has started, since the next log
				// doesn't occur until after the API request to get the project name (which could fail).
				$this->log("Started sync with server: $url");

				foreach($server['projects'] as $project){
					try{
						// The following function takes about 15 minutes to export project 48364 (10,445 records, 1,428 fields, 20MB)
						// from redcap.vanderbilt.edu to Mark's local.
						$this->importRecords($localProjectId, $url, $project);
					}
					catch(Exception $e){
						$this->log("An error occurred.  Click 'Show Details' for more info.", [
							'details' => $e->getMessage() . "\n" . $e->getTraceAsString()
						]);
					}
				}

				$this->log("Finished sync with server: $url");
			}
		}

		// Put the pid back the way it was before this cron job (likely doesn't matter, but wanted to be safe)
		$_GET['pid'] = $originalPid;

		return 'The ' . $this->getModuleName() . ' External Module job completed successfully.';
	}

	private function isTimeToRun($server){
		$syncNow = $this->getProjectSetting('sync-now');
		if($syncNow){
			$this->removeProjectSetting('sync-now');
			return true;
		}

		$dailyRecordImportHour = $server['daily-record-import-hour'];
		$dailyRecordImportMinute = $server['daily-record-import-minute'];

		if(empty($dailyRecordImportHour) || empty($dailyRecordImportMinute)){
			return false;
		}

		$dailyRecordImportHour = (int) $dailyRecordImportHour;
		$dailyRecordImportMinute = (int) $dailyRecordImportMinute;

		// We check the cron start time instead of the current time
		// in case another module's cron job ran us into the next minute.
		$cronStartTime = $_SERVER["REQUEST_TIME_FLOAT"];

		$currentHour = (int) date('G', $cronStartTime);
		$currentMinute = (int) date('i', $cronStartTime);  // The cast is especially important here to get rid of a possible leading zero.

		return $dailyRecordImportHour === $currentHour && $dailyRecordImportMinute === $currentMinute;
	}

	function importRecords($localProjectId, $url, $project){
		$apiKey = $project['api-key'];

		$this->log("
			<div>Exporting records from the remote project titled:</div>
			<div class='remote-project-title'>" . $this->getProjectTitle($url, $apiKey) . "</div>
		");

		$fieldNames = json_decode($this->apiRequest($url, $apiKey, [
			'content' => 'exportFieldNames'
		]), true);

		$recordIdFieldName = $fieldNames[0]['export_field_name'];

		$records = json_decode($this->apiRequest($url, $apiKey, [
			'content' => 'record',
			'fields' => [$recordIdFieldName]
		]), true);

		$recordIds = [];
		foreach($records as $record){
			$recordIds[] = $record[$recordIdFieldName];
		}

		// Use the number of fields times number of records as a metric to determine a reasonable chunk size.
		$numberOfDataPoints = count($fieldNames) * count($recordIds);
		$numberOfBatches = $numberOfDataPoints / 1000000;
		$batchSize = round(count($recordIds) / $numberOfBatches);
		$chunks = array_chunk($recordIds, $batchSize);
		$format = 'csv';

		for($i=0; $i<count($chunks); $i++){
			$chunk = $chunks[$i];

			$batchText = "batch " . ($i+1) . " of " . count($chunks);

			$this->log("Exporting $batchText");
			$response = $this->apiRequest($url, $apiKey, [
				'content' => 'record',
				'format' => $format,
				'records' => $chunk
			]);

			$this->log("Importing $batchText (and overwriting matching local records)");
			$results = \REDCap::saveData(
					(int)$localProjectId,
					$format,
					$response,
					'overwrite',
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					true // $removeLockedFields - We want to allow importing of locked forms/instances.
			);

			$results = $this->adjustSaveResults($results);

			$stopEarly = false;
			if(empty($results['errors'])){
				$message = "completed ";

				if(empty($results['warnings'])){
					$message .= 'successfully';
				}
				else{
					$message .= 'with warnings';
				}
			}
			else{
				$message = "did NOT complete successfully";
				$stopEarly = true;
			}

			$this->log("Import $message for $batchText", [
				'details' => json_encode($results, JSON_PRETTY_PRINT)
			]);

			if(!$project['leave-unlocked']){
				$this->log("Locking all forms/instances for $batchText");
				$this->framework->records->lock($results['ids']);
			}

			if($stopEarly){
				break;
			}
		}
	}

	private function adjustSaveResults($results){
		$results['warnings'] = array_filter($results['warnings'], function($warning){
			global $lang;

			if(strpos($warning[3], $lang['data_import_tool_197']) !== -1){
				return false;
			}

			return true;
		});

		return $results;
	}

	private function getProjectTitle($url, $apiKey){
		$response = json_decode($this->apiRequest($url, $apiKey, [
			'content' => 'project'
		]), true);

		return $response['project_title'];
	}

	private function apiRequest($url, $apiKey, $data){
		$data = array_merge(
			[
				'token' => $apiKey,
				'format' => 'json',
				'type' => 'flat',
				'rawOrLabel' => 'raw',
				'rawOrLabelHeaders' => 'raw',
				'exportCheckboxLabel' => 'false',
				'exportSurveyFields' => 'false',
				'exportDataAccessGroups' => 'false',
				'returnFormat' => 'json'
			],
			$data
		);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "$url/api/");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));

		$output = curl_exec($ch);

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);

		curl_close($ch);

		if(!empty($error)){
			throw new Exception("CURL Error: $error");
		}

		if($httpCode !== 200){
			throw new Exception("HTTP error code $httpCode received: $output");
		}

		return $output;
	}

	function validateSettings($settings){
		$checkNumericSetting = function($settingKey, $settingName, $min, $max) use ($settings) {
			$values = $settings[$settingKey];
			foreach($values as $value){
				if (!empty($value) && (!ctype_digit($value) || $value < $min || $value > $max)) {
					return "The $settingName specified must be between $min and $max.\n";
				}
			}
		};

		$message = "";
		$message .= $checkNumericSetting('daily-record-import-hour', 'hour', 0, 23);
		$message .= $checkNumericSetting('daily-record-import-minute', 'minute', 0, 59);

		return $message;
	}

	function renderSyncNowHtml(){
		$syncNow = $this->getProjectSetting('sync-now');
		$currentSyncMessage = null;
		if($syncNow){
			$currentSyncMessage = "A sync is scheduled to start in less than a minute...";
		}
		else{
			$result = $this->query("
				select cron_run_start
				from redcap_crons c
				join redcap_crons_history h
					on c.cron_id = h.cron_id
				join redcap_external_modules m
					on m.external_module_id = c.external_module_id
				where 
					directory_prefix = '" . $this->PREFIX . "'
					and cron_run_end is null
				order by ch_id desc
			");

			$row = $result->fetch_assoc();
			if($row){
				$currentSyncMessage = "A sync is in progress...";
			}
		}


		if($currentSyncMessage){
			?>
			<p><?=$currentSyncMessage?>  For information on canceling it, <a href="javascript:ExternalModules.Vanderbilt.APISyncExternalModule.showSyncCancellationDetails()" style="text-decoration: underline">click here</a>.</p>

			<div id="api-sync-module-cancellation-details" style="display: none;">
				<p>Only a REDCap system administrator can cancel a sync in progress.</p>

				<p>If you are an administrator, make sure any long running cron processes have finished (or kill them manually).  Once you're sure no cron API Sync tasks are still running, use the following query to manually mark the previous API Sync job as completed so another one can be started:</p>
				<br>
				<br>
				<pre>
					update
						redcap_crons c
						join redcap_crons_history h
							on c.cron_id = h.cron_id
						join redcap_external_modules m
							on m.external_module_id = c.external_module_id
					set
						cron_run_end = now(),
						cron_run_status = 'PROCESSING',
						cron_info = 'The job died unexpectedly and was manually marked as completed via SQL query.'
					where
						directory_prefix = '<?=$this->PREFIX?>'
						and cron_run_end is null
				</pre>
			</div>
			<?php
		}
		else{
			?>
			<form action="<?=$this->getUrl('sync-now.php')?>" method="post">
				<button>Sync Now</button>
			</form>
			<?php

		}
	}
}