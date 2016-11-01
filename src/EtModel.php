<?php
/**
 * Created by PhpStorm.
 * User: kene
 * Date: 2016-06-08
 * Time: 1:08 PM
 */

namespace ariad\exacttargetLaravel;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use ET_Client;
use ET_GET;
use ET_ClickEvent;
use ET_OpenEvent;
use ET_Continue;
use ET_DataExtension;
use ET_DataExtension_Row;
use ET_DataExtension_Column;

/**
 * Note: Fuel API by default sets getSinceLastBatch true for Events.
 *
 * Class EtModel
 * @package ariad\exacttargetLaravel
 */
class EtModel extends LaravelEtApi {

	/**
	 * @var the model to save Exact Target data too
	 */
	private $model_name;

	/**
	 * @var array map of ExactTarget Properties to Model Column names
	 * add a key: "default" with the value to add (IE if you need the ID for sender platform or Business Unit)
	 * add a key: "encrypt" to encrypt the data being stored using Laravel Crypt Facade
	 */
	private $map = [
		 'PlatformID' => ['column' => 'email_platform_id', 'default' => null],
		 'ObjectID' => ['column' => 'object_id'],
		 'SendID' => ['column' => 'send_id'],
		 'SubscriberKey' => ['column' => 'subscriber_key'],
		 'EventDate' => ['column' => 'event_date'],
		 'EventType' => ['column' => 'event_type'],
		 'TriggeredSendDefinitionObjectID' => ['column' => 'triggered_send_definition_object_id'],
		 'BatchID' => ['column' => 'batch_id'],
		 'URLID' => ['column' => 'urlid'],
		 'URL' => ['column' => 'url']
	];


	/**
	 * EtModel constructor.
	 * @param Client $model the Model Object to store the data from Exact Target
	 * @param Client $client
	 * @param ET_DataExtension_Row $fuelDe
	 * @param ET_DataExtension_Column $fuelDeColumn
	 * @param ET_DataExtension $fuelDext
	 * @param null $config added "map" to map Exact Target Properties to you Model's column names
	 */
	public function __construct($model, Client $client, ET_DataExtension_Row $fuelDe, ET_DataExtension_Column $fuelDeColumn, ET_DataExtension $fuelDext, $config = null) {

		$this->model = $model;

		if (array_key_exists('map', $config)) {
			$this->map = $config['map'];
			unset($config['map']);
		}

		parent::__construct($client, $fuelDe, $fuelDeColumn, $fuelDext, $config);

	}

	/**
	 * Override the ET function by getting totals from local storage (Model)
	 * @param $sendIDs
	 * @return array
	 */
	public function getTotals($sendIDs) {
		$event_type = $this->map['EventType']['column'];
		$send_id    = $this->map['SendID']['column'];

		$events = $this->model->whereIn($send_id, $sendIDs)
									 ->groupBy($send_id, $event_type)
									 ->get([$event_type, $send_id, DB::raw('count(' . $event_type . ') as totals')])
									 ->toArray();
		$totals = [];
		foreach ($events as $event) {
			if (array_key_exists($event[$send_id], $totals)) {
				$totals[$event[$send_id]][$event[$event_type]] = $event['totals'];
			}
			else {
				$totals[$event[$send_id]] = [$event[$event_type] => $event['totals']];
			}
		}
		return $totals;
	}

	/**
	 * @param $sendIDs
	 * @param bool $reset If true, this will get ALL Opens events from the selected SendID's
	 */
	public function getOpens($sendIDs, $reset = false) {
		$et_openevents = new \ET_OpenEvent();
		if ($reset) {
			$et_openevents->getSinceLastBatch = false;
		}
		$props = array("SendID", "SubscriberKey", "EventDate", "EventType");

		$this->get_events($et_openevents, $props, $this->setPropertyFilter('SendID', $sendIDs));

	}

	/**
	 * @param $sendIDs
	 * @param bool $reset If true, this will get ALL Click events from the selected SendID's
	 */
	public function getClicks($sendIDs, $reset = false) {
		$et_clickevents = new \ET_ClickEvent();
		if ($reset) {
			$et_clickevents->getSinceLastBatch = false;
		}
		$props = array("SendID", "SubscriberKey", "EventDate", "EventType", "URLID", "URL");
		$this->get_events($et_clickevents, $props, $this->setPropertyFilter('SendID', $sendIDs));

	}

	/**
	 * Get the total number of opens based on $filters
	 * @param $props
	 * @param $filters
	 * @return int
	 */
	private function get_events($et_events, $props, $filters) {
		$et_events->authStub = $this->fuel;
		$et_events->props    = $props;
		$et_events->filter   = $filters;

		$response = $et_events->get();

		Log::info('ET_Get: ', ['r' => print_r($response->results, 1)]);

		$this->store($response->results);

		while ($response->moreResults == true) {// Keep querying API if more results exist
			$response = new ET_Continue($this->fuel, $response->request_id);
			$this->store($response->results);
		}
		return;
	}

	/**
	 * Push the ET data into the Model
	 * @param $et_results
	 */
	private function store($et_results) {

		$insert = [];

		foreach ($et_results as $row) {
			$allowed = [];
			foreach ($this->map as $key => $columns) {
				if (property_exists($row, $key)) {
					if (array_key_exists('encrypt',$columns )) {
						$allowed[$columns['column']] = \Crypt::encrypt($row->$key);
					}
					else {
						$allowed[$columns['column']] = $row->$key;
					}
				}
				elseif (array_key_exists('default',$columns )) {
					$allowed[$columns['column']] = $columns['default'];
				}
			}
			$insert[] = $allowed;
		}

		$this->model->insert($insert);
	}


}