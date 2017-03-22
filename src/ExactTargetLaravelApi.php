<?php namespace ariad\exacttargetLaravel;

use FuelSdkPhp\ET_Automation;
use FuelSdkPhp\ET_BusinessUnit;
use FuelSdkPhp\ET_ContentArea;
use FuelSdkPhp\ET_DataExtensionTemplate;
use FuelSdkPhp\ET_DataExtractActivity;
use FuelSdkPhp\ET_ExtractDefinition;
use FuelSdkPhp\ET_ExtractDescription;
use FuelSdkPhp\ET_FilterDefinition;
use FuelSdkPhp\ET_FTPLocation;
use FuelSdkPhp\ET_Get;
use FuelSdkPhp\ET_Group;
use FuelSdkPhp\ET_Import;
use FuelSdkPhp\ET_Info;
use FuelSdkPhp\ET_List;
use FuelSdkPhp\ET_Portfolio;
use FuelSdkPhp\ET_QueryDefinition;
use FuelSdkPhp\ET_Role;
use FuelSdkPhp\ET_SenderProfile;
use FuelSdkPhp\ET_Template;
use FuelSdkPhp\ET_User;
use GuzzleHttp\Exception\BadResponseException as BadResponseException;
use FuelSdkPhp\ET_DataExtension_Column as ET_DataExtension_Column;
use GuzzleHttp\Exception\RequestException as RequestException;
use FuelSdkPhp\ET_DataExtension_Row as ET_DataExtension_Row;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface as ResponseInterface;
use FuelSdkPhp\ET_DataExtension as ET_DataExtension;
use GuzzleHttp\Psr7\Request as Request;
use FuelSdkPhp\ET_Client as ET_Client;
use FuelSdkPhp\ET_Organization as ET_Organization;
use FuelSdkPhp\ET_Folder as ET_Folder;
use FuelSdkPhp\ET_Email as ET_Email;
use FuelSdkPhp\ET_Asset as ET_Asset;
use FuelSdkPhp\ET_Patch as ET_Patch;
use FuelSdkPhp\ET_Post as ET_Post;
use GuzzleHttp\Client as Client;


/**
 *
 * Class ExactTargetLaravelApi
 *
 * @package App
 */
class ExactTargetLaravelApi implements ExactTargetLaravelInterface {

	use SerializeDataTrait;
	use DataFolderTypes;

	/**
	 * client id
	 * @var string
	 */
	protected $clientId;

	/**
	 * client secret
	 * @var array
	 */
	protected $clientSecret;

	/**
	 * base uri
	 * @var array
	 */
	protected $getTokenUri;

	/**
	 * Guzzle Client
	 * @var object
	 */
	protected $client;

	/**
	 * Fuel Client
	 * @var object
	 */
	protected $fuel;

	/**
	 * Fuel DE Object
	 * @var object
	 */
	protected $fuelDe;


	/**
	 * @param Client $client
	 * @param ET_Client $fuel
	 * @param ET_DataExtension_Row $fuelDe
	 * @param ET_DataExtension_Column $fuelDeColumn
	 * @param ET_DataExtension $fuelDext
	 * @param null $config Configuration passed takes precedence over configuration in file.
	 */
	function __construct() {
		$this->getTokenUri  = 'https://auth.exacttargetapis.com/v1/requestToken';
		$this->client       = new Client();
		$this->fuelDe       = new ET_DataExtension_Row();
		$this->fuelDeColumn = new ET_DataExtension_Column();
		$this->fuelDext     = new ET_DataExtension();
		$this->etAsset      = new ET_Asset();
		$this->fuelAccount  = new ET_Organization();
		$this->fuelFolder   = new ET_Folder();
		$this->config       = $this->getConfig();
		$this->clientId     = $this->config['clientid'];
		$this->clientSecret = $this->config['clientsecret'];
		$this->accessToken  = $this->getToken($this->clientId, $this->clientSecret, $this->getTokenUri);
	}

	public function getConfig() {
		//moved this from constructor so we can override instantiating with DB credentials if desired.
		$this->fuel = new ET_Client();
		if (file_exists(__DIR__ . '/../ExactTargetLaravelConfig.php')) {
			$config = include __DIR__ . '/../ExactTargetLaravelConfig.php';
		}
		return $config;
	}


	/**
	 * reaches out to Exact Target Rest API with client secret and Id
	 * returns the auth token
	 *
	 * Client is the guzzle object and all the methods you need
	 *
	 * @param $clientId
	 * @param $clientSecret
	 * @param $getTokenUri
	 * @param Client $client
	 * @return array
	 */
	public function getToken($clientId, $clientSecret, $getTokenUri) {
		//------------------
		// Get Access Token
		//------------------
		$params = [
			 'clientId' => $clientId,
			 'clientSecret' => $clientSecret
		];

		$params = json_encode($params);

		$headers = [
			 'Content-Type' => 'application/json',
			 'Accept' => 'application/json'
		];

		$post = $this->client->post($getTokenUri, ['body' => $params, 'headers' => $headers]);

		$response = json_decode($post->getBody());

		return compact('response');
	}


	/**
	 * POST
	 *
	 * /dataevents/key:{key}/rowset
	 *
	 * Upserts a batch of data extensions rows by key.
	 *
	 * @param $keys
	 * @param $values
	 * @param Client $client
	 * @return array
	 */
	public function upsertRowset($data, $dataExtensionKey) {

		$upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataevents/key:' . $dataExtensionKey . '/rowset';

		if (is_array($data)) {
			$data = $this->it_serializes_data($data);
		}

		$request['body'] = $data;

		$request['headers'] = [
			 'Content-Type' => 'application/json',
			 'Accept' => 'application/json',
			 'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
		];

		try {
			//post upsert
			$response     = $this->client->post($upsertUri, $request);
			$responseBody = json_decode($response->getStatusCode());

		}
		catch (BadResponseException $exception) {
			//spit out exception if curl fails or server is angry
			$exc = $exception->getResponse()->getBody(true);
			echo $exc . "\n";

		}

		return compact('responseBody');
	}


	/**
	 * SOAP WDSL
	 *
	 * uses the Fuel SDK to delete a row by Primary Key
	 * currently the v1 of the REST api does not support retrieval of data.
	 * Hopefully this will change in the near future
	 *
	 * @param $deName
	 * @param $props
	 * @return array -- the response from Exact Target
	 */
	public function deleteRow($deName, $props) {
		//new up & auth up ET Fuel
		$this->fuelDe->authStub = $this->fuel;

		$this->fuelDe->props = $props;

		$this->fuelDe->CustomerKey = $deName;

		$getRes = $this->fuelDe->delete();

		if ($getRes->status == true) {
			return $getRes->code;
		}

		return print 'Message: ' . $getRes->code . "\n";
	}


	/**
	 * @param $deName
	 *  Required -- Name of the Data Extension to query
	 *
	 * @return array
	 */
	public function getDeColumns($deName, $BusinessUnit=false) {

		//Get all Data Extensions Columns filter by specific DE

		$this->fuelDeColumn->authStub = $this->fuel;
		if ($BusinessUnit) {
			$this->fuelDeColumn->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		$this->fuelDeColumn->filter = array('Property' => 'CustomerKey', 'SimpleOperator' => 'equals', 'Value' => $deName);

		$getResult = $this->fuelDeColumn->get();

		if ($getResult->status == true) {
			return $getResult;
		}

		return $getResult;
	}

	public function getObjProps($obj) {
		$obj = new ET_Info($this->fuel, $obj);
		return $obj;
	}

	public function getUsers($BusinessUnit = false, $props = false) {
		$obj           = new ET_User();
		$obj->authStub = $this->fuel;

		if ($props) {
			$obj->props = $props;
		}
		return $obj->get();
	}

	public function getRoles($props = false) {
		$obj           = new ET_Role();
		$obj->authStub = $this->fuel;

		if ($props) {
			$obj->props = $props;
		}
		return $obj->get();
	}

	public function getRole($customer_key, $props = false) {
		$obj           = new ET_Role();
		$obj->authStub = $this->fuel;

		if ($props) {
			$obj->props = $props;
		}

		$obj->filter = ['Property' => 'Name', 'SimpleOperator' => 'equals', 'Value' => $customer_key];

		return $obj->get();
	}

	public function getAccount($filter = false) {
		$this->fuelAccount->authStub = $this->fuel;

		if ($filter) {
			foreach ($filter as $property => $value) {
				$this->fuelAccount->filter = ['Property' => $property, 'SimpleOperator' => 'equals', 'Value' => $value];
			}
		}

		$getResult = $this->fuelAccount->get();
		if ($getResult->status) {
			return $getResult;
		}
	}

	public function getAccountUsers($props = false) {
		$obj           = new ET_AccountUser();
		$obj->authStub = $this->fuel;

		if ($props) {
			$obj->props = $props;
		}
		return $obj->get();
	}

	public function getBusinessUnits() {
		$obj           = new ET_BusinessUnit();
		$obj->authStub = $this->fuel;

		return $obj->get();
	}

	public function createAccount($BusinessUnit, $props) {
		$obj           = new ET_Organization();
		$obj->authStub = $this->fuel;

		if ($BusinessUnit) {
			$obj->authStub->businessUnit = (object)['ID' => $BusinessUnit];
		};

		$obj->props = $props;

		return $obj->post();

	}

	public function createUser($BusinessUnit, $props) {
		$obj           = new ET_User();
		$obj->authStub = $this->fuel;

		if ($BusinessUnit) {
			$obj->authStub->businessUnit = (object)['ID' => $BusinessUnit];
		};

		$obj->props = $props;


		return $obj->post();
	}


	public function getFolders($BusinessUnit = false, $name = false) {
		$this->fuelFolder->authStub = $this->fuel;

		if ($BusinessUnit) {
			$this->fuelFolder->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}
		// $this->fuelFolder->props = ['Name','CustomerKey', 'ContentType'];
		if ($name) {
			$this->fuelFolder->filter = array(
				 'Property' => 'ContentType',
				 'SimpleOperator' => 'equals',
				 'Value' => $name
			);
		}

		$getResult = $this->fuelFolder->get();
		if ($getResult->status) {
			return $getResult;
		}
		return $getResult;
	}

	public function createFolder($BusinessUnit = false, $props) {
		$obj           = new ET_Folder();
		$obj->authStub = $this->fuel;

		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		if (array_key_exists('ParentFolder.ID', $props)) {
			$props['ParentFolder'] = (object)['ID' => $props['ParentFolder.ID'], 'IDSpecified' => true, 'ContentType' => $props['ParentFolder_ContentType']];
			unset($props['ParentFolder.ID'],$props['ParentFolder_ContentType']);
		}

		$obj->props = $props;

		return $obj->post();

	}

	/**
	 * @param $type Folder type
	 * @param $folder_name Name of the Folder
	 * @param bool $BusinessUnit (optional) the BusinessUnit to search
	 * @return string
	 */
	public function listFolderContents($type, $folder_name = false, $BusinessUnit = false) {
		switch ($type) {
			case 'email' :
				$obj      = new ET_Email();
				$property = 'Email.Folder';
				break;
			case 'groups' :
				return 'groups don\'t appear to be supported!';
			case 'datafilters' :
				$obj        = new ET_FilterDefinition();
				$obj->props = array('Client.ID', 'Categoryid', 'CustomerKey', 'DataFilter', 'Description', 'Name');
				break;
			default :
				return 'method ' . $type . ' not supported!';
		}

		$obj->authStub = $this->fuel;
		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}
//		$obj->props = array(
//			 'ID', 'CustomerKey', 'Name', 'Subject'
//		);
		if ($folder_name) {
			$obj->filter = array(
				 'Property' => $property,
				 'SimpleOperator' => 'equals',
				 'Value' => $folder_name
			);
		}

		$r = $obj->get();
		if ($r->status == true) {
			return $r;
		}
	}

	/**
	 * SOAP WDSL
	 *
	 * uses the Fuel SDK to grab all the rows of a given Data Extension
	 * currently the v1 of the REST api does not support retrieval of data.
	 * Hopefully this will change in the near future
	 *
	 *
	 * @param $keyName
	 *  This is an optional param if set along with primaryKey the result will be filtered to a single row by PrimaryKey
	 * @param $primaryKey
	 *  This is an optional param if set along with keyName the result will be filtered to a single row by PrimaryKey
	 * @param $deName
	 *  Required -- Name of the Data Extension to query
	 * @return array
	 *  Response from ET
	 */
	public function getRows($deName, $keyName = '', $primaryKey = '') {
		//get column names from DE
		$deColumns = $this->getDeColumns($deName);

		//new up & auth up ET Fuel
		$this->fuelDe->authStub = $this->fuel;

		$this->fuelDe->Name = $deName;

		//build array of Column names from DE
		foreach ($deColumns as $k => $v) {
			$this->fuelDe->props[] = $v->Name;
		}

		//if the function is called with these values -- filter by them
		if ($primaryKey !== '' && $keyName !== '') {
			$this->fuelDe->filter = array('Property' => $keyName, 'SimpleOperator' => 'equals', 'Value' => $primaryKey);
		}

		//get rows from the columns
		$results = $this->fuelDe->get();

		if ($results->status == false) {
			return print 'Exception Message: ' . $results->message . "\n";
		}

		if (!$results->moreResults) {
			return $results;
		}
		else {
			$moreResults = [];
		}


		while ($results->moreResults) {
			$moreResults[] = $this->fuelDe->GetMoreResults();
		}

		return $moreResults;
	}

	/**
	 * POST
	 *
	 * Asynchronously upserts a batch of data extensions rows by key.
	 *
	 * these async methods need testing when / if we have a need for async requests (which we will)
	 *
	 * /dataeventsasync/key:{key}/rowset
	 *
	 */
	public function asyncUpsertRowset($data, $deKey) {
		$upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataeventsasync/key:' . $deKey . '/rowset';

		if (is_array($data)) {
			$data = $this->it_serializes_data($data);
		}

		$request['body'] = $data;

		$request['headers'] = [
			 'Content-Type' => 'application/json',
			 'Accept' => 'application/json',
			 'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
		];

		try {
			//post upsert
			$promise = $this->client->postAsync($upsertUri, $request);
			$promise->then(
			//chain logic to the response (can fire from other classes or set booleans)
				 function (ResponseInterface $res) {
					 $response = $res->getStatusCode() . "\n";
				 },
				 function (RequestException $e) {
					 $response       = $e->getMessage() . "\n";
					 $responseMethod = $e->getRequest()->getMethod();
				 }
			);
			$promise->wait();
		}
		catch (BadResponseException $exception) {
			//spit out exception if curl fails or server is angry
			$exc = $exception->getResponse()->getBody(true);
			echo $exc;

		}
	}


	/**
	 * PUT
	 *
	 * Upserts a data extension row by key.
	 *
	 * /dataevents/key:{key}/rows/{primaryKeys}
	 */
	public function upsertRow($primaryKeyName, $primaryKeyValue, $data, $deKey) {
		$upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataevents/key:' . $deKey . '/rows/' . $primaryKeyName . ':' . $primaryKeyValue;

		$values = ["values" => $data];

		$request['body'] = json_encode($values);

		$request['headers'] = [
			 'Content-Type' => 'application/json',
			 'Accept' => 'application/json',
			 'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
		];

		try {
			//post upsert
			$response     = $this->client->put($upsertUri, $request);
			$responseBody = json_decode($response->getBody());
			$responseCode = json_decode($response->getStatusCode());

		}
		catch (BadResponseException $exception) {
			//spit out exception if curl fails or server is angry
			$exc = $exception->getResponse()->getBody(true);
			echo "Oh No! Something went wrong! " . $exc;
		}
		return compact('responseCode');
	}


	/**
	 * PUT
	 *
	 * Asynchronously upserts a data extension row by key.
	 *
	 * these async methods need testing when / if we have a need for async requests (which we will)
	 *
	 * /dataeventsasync/key:{key}/rows/{primaryKeys}
	 */
	public function asyncUpsertRow($primaryKeyName, $primaryKeyValue, $data, $deKey) {
		$upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataeventsasync/key:' . $deKey . '/rows/' . $primaryKeyName . ':' . $primaryKeyValue;

		$request['headers'] = [
			 'Content-Type' => 'application/json',
			 'Accept' => 'application/json',
			 'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
		];

		//api implementation style
		if (is_array($data)) {
			$data = $this->it_serializes_data($data);
		}

		$request['body'] = $data;

		try {
			//post upsert
			$promise = $this->client->putAsync($upsertUri, $request);
			$promise->then(
			//chain logic to the response (can fire from other classes or set booleans)
				 function (ResponseInterface $res) {
					 echo $res->getStatusCode() . "\n";
				 },
				 function (RequestException $e) {
					 echo $e->getMessage() . "\n";
					 echo $e->getRequest()->getMethod();
				 }
			);
			$promise->wait();
		}
		catch (BadResponseException $exception) {
			//spit out exception if curl fails or server is angry
			$exc = $exception->getResponse()->getBody(true);
			echo "Oh No! Something went wrong! " . $exc;
		}

		return compact('promise');
	}

	/**
	 * Create a Data extension by passing an array of DE Name keys => Column props values.
	 *
	 * @param $deStructures
	 * @return array (response)
	 */
	public function createRow($deName, $props) {

		//new up & auth up ET Fuel
		$this->fuelDe->authStub = $this->fuel;

		$this->fuelDe->Name = $deName;

		$this->fuelDe->props = $props;

		$getRes = $this->fuelDe->post();

		if ($getRes->status == true) {
			return $getRes->code;
		}
		return $getRes->message;
	}


	/**
	 * POST
	 *
	 * To validate an email address, perform an HTTP POST specifying the email address and validators
	 * to be used in the payload of the HTTP POST. You can use more than one validator in the same call.
	 *
	 * /validateEmail
	 *
	 */
	public function validateEmail($email) {
		$request['headers'] = [
			 'Content-Type' => 'application/json',
			 'Accept' => 'application/json',
			 'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
		];

		$request['body'] = json_encode([
													  "email" => $email,
													  "validators" => ["SyntaxValidator", "MXValidator", "ListDetectiveValidator"]
												 ]);

		$response = $this->client->post('https://www.exacttargetapis.com/address/v1/validateEmail', $request);

		$responseBody = json_decode($response->getBody());

		$responseCode = json_decode($response->getStatusCode());

		return compact('responseCode', 'responseBody');
	}


	/**
	 * @param $deStructures
	 * @deprecated use createDataExtension
	 */
	public function createDe($deStructures) {
		return $this->createDataExtension($deStructures);
	}

	/**
	 * Create a Data extension by passing an array of DE Name keys => Column props values.
	 *
	 * @param $deStructures
	 * @return array (response)
	 */
	public function createDataExtension($props) {
		$this->fuelDext->authStub = $this->fuel;
		$this->fuelDext->props = [];
		unset($this->fuelDext->filters);
		$props_available       = [
			 'CategoryID',
			 'Client',
			 'CorrelationID',
			 'CustomerKey',
			 'DataRetentionPeriod',
			 'DataRetentionPeriodLength',
			 'DataRetentionPeriodUnitOfMeasure',
			 'DeleteAtEndOfRetentionPeriod',
			 'Description',
			 'ID',
			 'IsSendable',
			 'IsTestable',
			 'Name',
			 'Owner',
			 'PartnerKey',
			 'PartnerProperties',
			 'ResetRetentionPeriodOnImport',
			 'RetainUntil',
			 'RowBasedRetention',
			 'SendableDataExtensionField',
			 'SendableSubscriberField',
			 'Status',
			 'Template',
		];
		foreach ($props as $prop => $value) {
			if (in_array($prop, $props_available)) {
				$this->fuelDext->props[$prop] = $value;
			}
		}

		$this->fuelDext->columns = [];

		if (array_key_exists('Columns', $props)) {
			// don't have Columns if there's a Template specified
			foreach ($props['Columns'] as $key => $val) {
				$this->fuelDext->columns[] = $val;
			}
		}
		try {
			$getRes = $this->fuelDext->post();

			return $getRes;
		}
		catch (Exception $e) {
			return 'Message: ' . $e->getMessage() . "\n";
		}

		return compact('getRes');
	}

	public function deleteDe($props) {
		$this->fuelDext->authStub = $this->fuel;

		$this->fuelDext->props = $props;

		try {
			$getRes = $this->fuelDext->delete();
			return $getRes->code;
		}
		catch (Exception $e) {
			return 'Message: ' . $e->getMessage() . "\n";
		}

	}

	public function getPortfolio($BusinessUnit = false, $props = false) {

		$obj           = new ET_Portfolio();
		$obj->authStub = $this->fuel;

		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}
		if ($props) {
			$this->props = $props;
		}
		return $obj->get();

	}

	/**
	 * Upload a File to Exact Target FTP
	 */
	public function it_uploads_a_file_via_ftp($host, $userName, $userPass, $remoteFilePath, $localFilePath) {
		$conn_id = ftp_connect($host);

		$login_result = ftp_login($conn_id, $userName, $userPass);

		ftp_pasv($conn_id, true);

		if (ftp_chdir($conn_id, "Import") && ftp_put($conn_id, $remoteFilePath, $localFilePath, FTP_BINARY)) {
			ftp_close($conn_id);
			return true;
		}

		echo "There was a problem while uploading $localFilePath\n";
		ftp_close($conn_id);
		return false;
	}

	/**
	 * Transfer a File from FTP to Exact Target Portfolio
	 *
	 * @param $props array("filePath" => $_SERVER['PWD'] . '/sample-asset-TestFilePath.txt');
	 * see tests for expected array structure of $props
	 * @return true
	 *
	 */
	public function it_creates_a_portfolio_file($props) {
		$objType = 'Portfolio';

		try {
			$response = new ET_Post($this->fuel, $objType, $props);
			if ($response->status == 1) {
				return true;
			}
			return $response;
		}
		catch (Exception $e) {
			throw new Exception($e);
		}
	}

	/**
	 * Returns all Data Extension Definitions from SFMC
	 * @param bool $BusinessUnit [optional] Client ID
	 * @return array|bool Raw data from SFMC
	 */
	public function getDes($BusinessUnit = false) {
		$this->fuelDext->authStub = $this->fuel;

		if ($BusinessUnit) {
			$this->fuelDext->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}
		try {
			$getRes = $this->fuelDext->get();

		}
		catch (Exception $e) {
			Log::error("Error getting Data Extension", [$e]);
			return false;
		}

		return $getRes;

	}

	/**
	 * Returns a specific Data Extension Definition identified by CustomerKey from SFMC
	 * @param $name
	 * @param bool $BusinessUnit [optional] Client ID
	 * @return array|bool Raw data from SFMC
	 */
	public function getDe($name, $BusinessUnit = false) {
		$this->fuelDext->authStub = $this->fuel;

		$this->fuelDext->props = array('Client.ID', 'CustomerKey', 'Name', 'CategoryID');

		$this->fuelDext->filter = array('Property' => 'CustomerKey', 'SimpleOperator' => 'equals', 'Value' => $name);

		if ($BusinessUnit) {
			//define Business Unit ID (mid)
			$this->fuelDext->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];

		}

		try {
			$getRes = $this->fuelDext->get();

		}
		catch (Exception $e) {
			Log::error("Error getting Data Extension", [$e]);
			return false;
		}

		return $getRes;

	}

	/**
	 * Request DataExtension Data from SFMC
	 * @param String $name the Name of the Data Extension
	 * @param Array $columns the Columns to import
	 * @param bool $BusinessUnit [optional] Client ID
	 * @return ET_Get Raw results from ExactTarget
	 */
	public function getDataExtensionData($name, $columns, $BusinessUnit=false) {
		$obj = new ET_DataExtension_Row();

		$obj->authStub = $this->fuel;

		$obj->Name = $name;
		$obj->props = $columns;

		if ($BusinessUnit) {
			//define Business Unit ID (mid)
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}
		return $obj->get();
	}

	public function getDataExtensionTemplate($BusinessUnit = false) {
		$obj = new ET_DataExtensionTemplate();

		$obj->authStub = $this->fuel;

		if ($BusinessUnit) {
			//define Business Unit ID (mid)
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}
		return $obj->get();	}

	/**
	 * @param bool $BusinessUnit
	 * @param bool $CustomerKey
	 * @return array|ET_Get
	 */
	public function getGroups($BusinessUnit = false, $CustomerKey = false) {

		$obj = new ET_Group();
		$obj->authStub = $this->fuel;

		if ($BusinessUnit) {
			//define Business Unit ID (mid)
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		if ($CustomerKey) {
			$obj->filter = array(
				 'Property' => 'CustomerKey',
				 'SimpleOperator' => 'equals',
				 'Value' => $CustomerKey
			);
		}

		$r = $obj->get();
		if ($r->status == true) {
			return $r;
		}
		return ['failed' => $r];

	}

	public function getFilterDefinitions($BusinessUnit = false, $name = false) {
		$obj        = new ET_FilterDefinition();
		$obj->authStub = $this->fuel;
		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		$r = $obj->get();
		if ($r->status == true) {
			return $r;
		}
		return ['failed' => $r];

	}

	public function getQueryDefinitions($BusinessUnit = false, $folder_name = false) {

		$obj = new ET_QueryDefinition();
		// $obj->props = array('Client.ID','Categoryid','CustomerKey','DataFilter','Description','Name');

		$obj->authStub = $this->fuel;
		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

//		if ($folder_name) {
//			$obj->filter = array(
//				 'Property' => $property,
//				 'SimpleOperator' => 'equals',
//				 'Value' => $folder_name
//			);
//		}

		$r = $obj->get();
		if ($r->status == true) {
			return $r;
		}


	}

	/**
	 * retrieve a Extract Description from SFMC
	 * @param bool $BusinessUnit
	 * @param bool $name
	 * @return array|ET_Get
	 */
	public function getExtractDescription($BusinessUnit = false, $name = false) {

		$obj = new ET_ExtractDescription();
		// $obj->props = array('Client.ID','Categoryid','CustomerKey','DataFilter','Description','Name');

		$obj->authStub = $this->fuel;
		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		$r = $obj->get();
		if ($r->status == true) {
			return $r;
		}
		return ['failed' => $r];
	}

	/**
	 * retrieve an Extract Definition from SFMC
	 * @param bool $BusinessUnit
	 * @param bool $name
	 * @return array
	 */
	public function getExtractDefinition($BusinessUnit = false, $name = false) {

		$obj = new ET_ExtractDefinition();
		// $obj->props = array('Client.ID','Categoryid','CustomerKey','DataFilter','Description','Name');

		$obj->authStub = $this->fuel;
		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		$r = $obj->get();
		if ($r->status == true) {
			return $r;
		}
		return ['failed' => $r];
	}

	/**
	 * retrieve or run an Extract Activity
	 * @param bool $BusinessUnit
	 * @param bool $name
	 * @return array
	 */
	public function getDataExtractActivity($BusinessUnit = false, $name = false) {

		$obj = new ET_DataExtractActivity();
		// $obj->props = array('Client.ID','Categoryid','CustomerKey','DataFilter','Description','Name');

		$obj->authStub = $this->fuel;
		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		$r = $obj->get();
		if ($r->status == true) {
			return $r;
		}
		return ['failed' => $r];
	}
	/**
	 * ET_Client.ET_Import follows a different pattern than the API Objects
	 * @param bool $BusinessUnit
	 * @return ET_Get
	 */
	public function getAllImports($BusinessUnit = false) {
		$imports           = new ET_Import();
		$imports->authStub = $this->fuel;
		if ($BusinessUnit) {
			$imports->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		$all_imports = $imports->all();
		return $all_imports;

	}

	/**
	 * ET_Client.ET_Import follows a different pattern than the API Objects
	 * @param bool $BusinessUnit
	 * @return ET_Get
	 */
	public function getImport($BusinessUnit, $CustomerKey = false) {
		$imports           = new ET_Import();
		$imports->authStub = $this->fuel;
		if ($BusinessUnit) {
			$imports->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		if ($CustomerKey) {
			$imports->filter = array(
				 "Property" => "CustomerKey",
				 "SimpleOperator" => "equals",
				 "Value" => $CustomerKey
			);
		}
		$all_imports = $imports->get();
		return $all_imports;

	}

	public function getAutomation($BusinessUnit, $CustomerKey = false) {
		$automations = new ET_Automation();
		$automations->authStub = $this->fuel;
		if ($BusinessUnit) {
			$automations->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		if ($CustomerKey) {
			$automations->filter = array(
				 "Property" => "CustomerKey",
				 "SimpleOperator" => "equals",
				 "Value" => $CustomerKey
			);
		}
		$all_automations = $automations->get();
		return $all_automations;
	}

	public function getSends($sendIds, $startDate = null, $endDate = null) {


		$sendFilter = array();
		$objectType = "Send";

		$sendProps = array(
			 'ID',
			 'Client.ID',
			 'SentDate',
			 'HardBounces',
			 'SoftBounces',
			 'OtherBounces',
			 'UniqueClicks',
			 'UniqueOpens',
			 'NumberSent',
			 'NumberDelivered',
			 'Unsubscribes',
			 'MissingAddresses',
			 'Subject',
			 'PreviewURL',
			 'Status',
			 'IsMultipart',
			 'EmailName');

//        $startDate = date('Y-m-d\TH:i:s', strtotime('-365 day'));
//        $endDate = date('Y-m-d\TH:i:s');

		if ($startDate && $endDate) {
			if (count($sendIds) > 1) {
				$sendFilter = array(
					 'LeftOperand' => array(
						  'Property' => 'SentDate',
						  'SimpleOperator' => 'between',
						  'Value' => array($startDate, $endDate)
					 ),
					 'LogicalOperator' =>
						  'AND',
					 'RightOperand' => array(
						  'Property' => 'ID',
						  'SimpleOperator' => 'IN',
						  'Value' => $sendIds
					 )
				);
			}
			else {
				$sendFilter = array(
					 'LeftOperand' => array(
						  'Property' => 'SentDate',
						  'SimpleOperator' => 'between',
						  'Value' => array($startDate, $endDate)
					 ),
					 'LogicalOperator' =>
						  'AND',
					 'RightOperand' => array(
						  'Property' => 'ID',
						  'SimpleOperator' => 'equals',
						  'Value' => $sendIds[0]
					 )
				);
			}
		}
		else {
			if (count($sendIds) > 0) {
				if (count($sendIds) > 1) {
					$sendFilter = array('Property' => 'ID', 'SimpleOperator' => 'IN', 'Value' => $sendIds);
				}
				else {
					$sendFilter = array('Property' => 'ID', 'SimpleOperator' => 'equals', 'Value' => $sendIds[0]);
				}
			}
		}

		$getRes = new ET_Get($this->fuel, $objectType, $sendProps, $sendFilter);

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error getting ET Sends(stats)', [$getRes]);
			return false;
		}
	}


	/**
	 * Description: GetTotals will grab total clicks or sends ET does not return total opens or clicks in the send object
	 *
	 * @param authStub Object ET client
	 * @param trackingMethod String ET_OpenEvent or ET_ClickEvent
	 * @param sendID Integer SendID to count opens or clicks
	 *
	 * @deprecated this function doesn't save the data to any storage, if you need tracking data don't use.
	 */
	public function getTotals($sendIDs) {

		$total_opens  = $this->getOpens($sendIDs);
		$total_clicks = $this->getClicks($sendIDs);

		Log::info('total_opens: ' . print_r($total_opens, 1) . 'total_clicks: ' . print_r($total_clicks, 1));
		return ['clicks' => $total_clicks, 'opens' => $total_opens];
	}

	public function getOpens($sendIDs) {
		$et_openevents = new ET_OpenEvent();
		$props         = array("SendID", "SubscriberKey", "EventDate", "EventType");

		$total_opens = $this->get_total_events($et_openevents, $props, $this->setPropertyFilter('SendID', $sendIDs));

	}

	public function getClicks($sendIDs) {
		$et_clickevents = new ET_ClickEvent();
		$props          = array("SendID", "SubscriberKey", "EventDate", "EventType", "URLID", "URL");

		$total_clicks = $this->get_total_events($et_clickevents, $props, $this->setPropertyFilter('SendID', $sendIDs));

	}

	/**
	 * write the correct filter for SendID
	 * @param $sendIDs
	 * @return ExactTarget Filter
	 */
	protected function setPropertyFilter($property, Array $sendIDs) {
		if (count($sendIDs) > 1) {
			$operator = 'IN';
			$ids      = $sendIDs;
		}
		else {
			$operator = 'equals';
			$ids      = $sendIDs[0];
		}
		return array('Property' => $property, 'SimpleOperator' => $operator, 'Value' => $ids);
	}

	/**
	 * Get the total number of opens based on $filters
	 * @param $props
	 * @param $filters
	 * @return int
	 */
	private function get_total_events($et_events, $props, $filters) {
		$et_events->authStub = $this->fuel;
		$et_events->props    = $props;
		$et_events->filter   = $filters;

		$response = $et_events->get();

		Log::info('ET_Get: ', ['r' => print_r($response->results, 1)]);

		$counts = $this->reduce_total_events($response->results);

		while ($response->moreResults == true) {// Keep querying API if more results exist
			$response = new ET_Continue($this->fuel, $response->request_id);
			$counts   = $this->reduce_total_events($response->results, $counts);
		}
// Now we have totals of the event
		return $counts;
	}

	/**
	 * reduce results into array of sendIDs with totals for results type
	 * @param Array $results
	 */
	private function reduce_total_events($results, $counts = []) {
		foreach ($results as $result) {
			Log::info('===> SendID: ' . $result->SendID);
			if (array_key_exists($result->SendID, $counts)) {
				$counts[$result->SendID]++;
			}
			else {
				$counts[$result->SendID] = 1;
			}
		}
		return $counts;
	}


	public function createEmail($name, $subject, $html, $props = []) {
		$email           = new ET_Email();
		$email->authStub = $this->fuel;

		$customer_key = uniqid(substr($name, 0, 10) . '::');

		$email->props = array(
			 'CustomerKey' => $customer_key,
			 'Name' => $name,
			 'Subject' => $subject,
			 'HTMLBody' => $html
		);

		$email->props = array_merge($email->props, $props);

		$getRes = $email->post();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error creating ET email(createEmail). Message: ', [$getRes]);
			return $getRes;
		}

	}

	public function retrieveEmails($name = null, $BusinessUnit = false) {
		$email           = new ET_Email();
		$email->authStub = $this->fuel;
		if ($BusinessUnit) {
			$email->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}
		if ($name) {
			$email->filter = array(
				 'Property' => 'CustomerKey',
				 'SimpleOperator' => 'equals',
				 'Value' => $name
			);
		}

		$getRes = $email->get();
		if ($getRes->status == true) {
			return $getRes;
		}
		else {

			Log::error('Error retrieving ET email(retrieveEmails). Message: ' . $getRes->message);
			return false;
		}

	}

	public function retrieveTemplates($name = null, $BusinessUnit = false) {
		$email           = new ET_Template();
		$email->authStub = $this->fuel;
		if ($BusinessUnit) {
			$email->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}
		if ($name) {
			$email->filter = array(
				 'Property' => 'CustomerKey',
				 'SimpleOperator' => 'equals',
				 'Value' => $name
			);
		}

		$getRes = $email->get();
		if ($getRes->status == true) {
			return $getRes;
		}
		else {

			Log::error('Error retrieving ET_Template. Message: ' . $getRes->message);
			return false;
		}

	}


	public function deleteEmails($id) {
		$email           = new \ET_Email();
		$email->authStub = $this->fuel;
		$email->props    = array(
			 'ID' => $id
		);
		$getRes          = $email->delete();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error retrieving ET email(retrieveEmails). Message: ' . $getRes->message);
			return false;
		}
	}


	/**
	 * Sends email to ET using a Data Extension
	 * @param $email Email ID
	 * @param bool|false $DEname Data Extension Customer Key
	 * @param string $emailClassification Email Classification
	 * @return bool|\ET_Perform Perform response
	 * @throws \Exception
	 */
	public function sendEmailToDataExtension($email, $publicationListID, $DEname = false, $emailClassification = "Default Commercial", $properties = []) {
		$SendClassificationCustomerKey = $emailClassification;
		$EmailIDForSendDefinition      = $email;
		$sd                            = new \ET_Email_SendDefinition();
		$sd->authStub                  = $this->fuel;
		$sd->props                     = array(
			 'Name' => uniqid(),
			 'CustomerKey' => uniqid(),
			 'Description' => "Created with ExacttargetLaravel",
			 'SendClassification' => array("CustomerKey" => $SendClassificationCustomerKey),
			 'Email' => array("ID" => $EmailIDForSendDefinition)
		);
		$allowed_properties            = ['SenderProfile', 'DeliveryProfile'];
		foreach ($properties as $key => $property) {
			if (in_array($key, $allowed_properties)) {
				$sd->props[$key] = $property;
			}
		}

		if ($DEname) {
			$sd->props["SendDefinitionList"] = array(
				 "CustomerKey" => $DEname,
				 'List' => array(
					  'ID' => $publicationListID,
					  'IDSpecified' => true
				 ),
				 "DataSourceTypeID" => "CustomObject"
			);
		}

		$getRes = $sd->post();

		//$getRes = $this->fuel->SendEmailToDataExtension($email, $DEname, $emailClassification);
		if ($getRes->status == 'true') {
			$res_send = $sd->send();
			Log::debug('sendEmailToDataExtension', [$res_send]);
			return $res_send;
		}
		else {
			Log::error('Error creating ET email(createSendDefinition)', [$getRes]);
			return false;
		}
	}

	public function retrieveContentAreas($BusinessUnit = false, $name = false) {
		$obj = new ET_ContentArea();
		$obj->authStub = $this->fuel;

		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}

		return $obj->get();

	}


	public function deleteSendDefinition($name) {
		$sd           = new \ET_Email_SendDefinition();
		$sd->authStub = $this->fuel;

		$sd->props = array(
			 'CustomerKey' => $name
		);

		$getRes = $sd->delete();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error deleting SendDefinition(deleteSendDefinition). Message: ', [$getRes]);
			return false;
		}

	}

	public function getSendDefinitions() {
		$sd           = new \ET_Email_SendDefinition();
		$sd->authStub = $this->fuel;
		$sd->props    = array(
			 'Name',
			 'Client.ID',
			 'CustomerKey',
			 'Description',
			 'Email.ID',
			 'CategoryID',
			 'SendDefinitionList',
			 'SenderProfile.CustomerKey'
		);

		$getRes = $sd->get();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error retrieving (getSendDefinition)', [$getRes]);
			return false;
		}
	}


	public function getSendClassifications() {
		$objectType = "SendClassification";
		$sendProps  = array(
			 'ObjectID',
			 'Client.ID',
			 'CustomerKey',
			 'Name',
			 'SendClassificationType',
			 'Description',
			 'SenderProfile.CustomerKey',
			 'DeliveryProfile.CustomerKey',
			 'ArchiveEmail'
		);

		$sendFilter = null;
		$getRes     = new ET_Get($this->fuel, $objectType, $sendProps, $sendFilter);

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error geting SendClassification ET (getSendClassification).', [$getRes]);
			return false;
		}
	}

	public function getSenderProfiles($name, $BusinessUnit = false, $props = false) {
		$obj = new ET_SenderProfile();

		$obj->authStub = $this->fuel;

		if ($BusinessUnit) {
			$obj->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
		}


		if ($props) {
			$obj->props = $props;
		}
		else {
			$obj->props = array(
				 'ObjectID',
				 'Client.ID',
				 'CustomerKey',
				 'Name',
				 'FromName',
				 'FromAddress',
				 'Description',
			);
		}

		$r = $obj->get();

		return $r;

	}


	public function getUnsubscribes() {

		$sc           = new \ET_Subscriber();
		$sc->authStub = $this->fuel;

		$sc->props = array(
			 'EmailAddress',
			 'Client.ID',
			 'Status'
		);


		$sc->filter = array(
			 'Property' => 'Status', 'SimpleOperator' => 'equals', 'Value' => 'Unsubscribed'
		);

		$getRes = $sc->get();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error geting Unsubscribes ET (getUnsubscribes)', [$getRes]);
			return false;
		}
	}

	public function getUnsubscribed($email) {
		$sc           = new \ET_Subscriber();
		$sc->authStub = $this->fuel;

//        $sc->props = array(
//            'EmailAddress',
//            'Client.ID',
//            'Status'
//        );


		$sc->filter = array(
			 'LeftOperand' => array('Property' => 'Status', 'SimpleOperator' => 'equals', 'Value' => 'Unsubscribed'),
			 'LogicalOperator' =>
				  'AND',
			 'RightOperand' => array(
				  'Property' => 'SubscriberKey',
				  'SimpleOperator' => 'equals',
				  'Value' => $email
			 )

		);
		$getRes     = $sc->get();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error geting Unsubscribed ET (getUnsubscribed)', [$getRes]);
			//throw new \Exception('could not get Unsubscribe Status');
			return false;
		}
	}


	public function getListSubscribers($list) {
		$sc           = new \ET_List_Subscriber();
		$sc->authStub = $this->fuel;

//        $sc->props = array(
//            'SubscriberKey',
//            'Client.ID',
//            'Status',
//            'ListID',
//            'UnsubscribedDate'
//        );


//        $sc->filter = array(
//            'Property' => 'ListID','SimpleOperator' => 'equals','Value' => $list
//        );
		$getRes = $sc->get();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error geting List Subscribers ET (getListSubscribers)', [$getRes]);
			return false;
		}
	}


	/**
	 * @param $list Publication List ID
	 * @param $email Email Address / SubscriberKey
	 * @param $status Status /Unsubscribed-Active
	 * @return bool|\ET_Patch
	 */
	public function UpdateListSubscriber($list, $email, $status) {
		$s           = new \ET_Subscriber();
		$s->authStub = $this->fuel;


		$s->props = array(
			 "SubscriberKey" => $email,
			 "Lists" => array(
				  "ID" => $list
			 ),
			 "Status" => $status
		);

		$getRes = $s->patch();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error('Error getting UpdateListSubscribers ET (UpdateListSubscribers)', [$getRes]);
			return false;
		}

	}

	public function getTriggeredSends() {
		$ts           = new \ET_TriggeredSend();
		$ts->authStub = $this->fuel;


		$getRes = $ts->get();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error("Error getting Triggered Sends", [$getRes]);
			return $getRes;
		}
	}

	public function deleteTriggeredSend($name) {
		$ts           = new \ET_TriggeredSend();
		$ts->authStub = $this->fuel;

		$ts->props = array(
			 'CustomerKey' => $name
		);

		$getRes = $ts->delete();

		if ($getRes->status == true) {
			return $getRes;
		}
		else {
			Log::error("Error deleting Triggered Send", [$getRes]);
			return $getRes;
		}
	}


	/**
	 * Sends a Triggered Send to defined Email Address
	 * @param $email Email Address to send to
	 * @param $emailid Email ID (content of the email to be sent)
	 * @param $sendClassification the Send Classification usually Default Commercial
	 * @param $properties array of extra properties, must be in allowed properties to be passed to ExactTarget.
	 * @return bool|\ET_Patch
	 * @throws \Exception
	 */
	public function sendTriggered($email, $emailid, $sendClassification = "Default Commercial", $properties = []) {

		$name         = uniqid();
		$ts           = new \ET_TriggeredSend();
		$ts->authStub = $this->fuel;

		$ts->props = array(
			 'CustomerKey' => $name,
			 'Name' => $name,
			 'Description' => 'Lantern Test Triggered Send',
			 'Email' => array(
				  'ID' => $emailid
			 ),
			 'SendClassification' => array(
				  'CustomerKey' => $sendClassification
			 ),
			 'EmailSubject' => 'Testing Lantern Triggered Send',
			 'TriggeredSendStatus' => 'Active',
			 'RefreshContent' => 'true',
			 'SuppressTracking' => 'true',
			 'Priority' => 'High'
		);

		$allowed_properties = ['SenderProfile', 'DeliveryProfile'];
		foreach ($properties as $key => $property) {
			if (in_array($key, $allowed_properties)) {
				$ts->props[$key] = $property;
			}
		}

		$getRes = $ts->post();

		if ($getRes->status == true) {
			$patchTrig           = new \ET_TriggeredSend();
			$patchTrig->authStub = $this->fuel;
			$patchTrig->props    = array(
				 'CustomerKey' => $name,
				 'TriggeredSendStatus' => 'Active',
				 'RefreshContent' => 'true'
			);

			if (is_array($email)) {
				$subscribers = [];
				foreach ($email as $e) {
					$subscribers[] = [
						 'EmailAddress' => $e,
						 'SubscriberKey' => $e
					];
				}
				$patchTrig->subscribers = $subscribers;
			}
			else {
				$patchTrig->subscribers = [
					 [
						  'EmailAddress' => $email,
						  'SubscriberKey' => $email,
					 ]
				];
			}


			$patchResult = $patchTrig->patch();
			$sendresult  = $patchTrig->send();
			if ($patchResult->status == true && $sendresult->status == true) {
				return $patchResult;
			}
			else {
				Log::error("Error Sending Triggered Send", [$patchResult, $sendresult]);
				return false;
			}

		}
		else {
			Log::error("Error (sendTriggered)", [$getRes]);
			return false;
		}

	}
}