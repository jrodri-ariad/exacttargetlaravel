<?php
namespace FuelSdkPhp;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

require(__DIR__ . '/soap-wsse.php');
require(__DIR__ . '/JWT.php');

class ET_Client extends \SoapClient {
	public $packageName, $packageFolders, $parentFolders;
	private                               $wsdlLoc, $debugSOAP, $lastHTTPCode, $clientId,
	                                      $clientSecret, $appsignature, $endpoint,
	                                      $tenantTokens, $tenantKey, $xmlLoc;

	function __construct($getWSDL = false, $debug = false, $params = null) {
		$tenantTokens = [];
		$this->config = false;
		$this->config = $this->getConfig();
		$this->xmlLoc = __DIR__ . '/ExactTargetWSDL.xml';
		if ($this->config) {
			$this->wsdlLoc      = $this->config['defaultwsdl'];
			$this->clientId     = $this->config['clientid'];
			$this->clientSecret = $this->config['clientsecret'];
			$this->appsignature = $this->config['appsignature'];
			if (array_key_exists('xmlloc', $this->config)) {
				$this->xmlLoc = $this->config['xmlloc'];
			}
		}
		// allow overwriting the config file
		if ($params && array_key_exists('defaultwsdl', $params)) {
			$this->wsdlLoc = $params['defaultwsdl'];
		}
		else {
			$this->wsdlLoc = "https://webservice.exacttarget.com/etframework.wsdl";
		}
		if ($params && array_key_exists('clientid', $params)) {
			$this->clientId = $params['clientid'];
		}
		if ($params && array_key_exists('clientsecret', $params)) {
			$this->clientSecret = $params['clientsecret'];
		}
		if ($params && array_key_exists('appsignature', $params)) {
			$this->appsignature = $params['appsignature'];
		}
		if ($params && array_key_exists('xmlloc', $params)) {
			$this->xmlLoc = $params['xmlloc'];
		}
		$this->debugSOAP = $debug;
		if (!property_exists($this, 'clientId') || is_null($this->clientId) || !property_exists($this,
		                                                                                        'clientSecret') || is_null($this->clientSecret)
		) {
			throw new \Exception('clientid or clientsecret is null: Must be provided in config file or passed when instantiating ET_Client');
		}
		if ($getWSDL) {
			$this->CreateWSDL($this->wsdlLoc);
		}
		if ($params && array_key_exists('jwt', $params)) {
			if (!property_exists($this, 'appsignature') || is_null($this->appsignature)) {
				throw new \Exception('Unable to utilize JWT for SSO without appsignature: Must be provided in config file or passed when instantiating ET_Client');
			}
			$decodedJWT = JWT::decode($params['jwt'], $this->appsignature);
			$dv         = new \DateInterval('PT' . $decodedJWT->request->user->expiresIn . 'S');
			$newexpTime = new \DateTime();
			$this->setAuthToken($this->tenantKey, $decodedJWT->request->user->oauthToken, $newexpTime->add($dv));
			$this->setInternalAuthToken($this->tenantKey, $decodedJWT->request->user->internalOauthToken);
			$this->setRefreshToken($this->tenantKey, $decodedJWT->request->user->refreshToken);
			$this->packageName = $decodedJWT->request->application->package;
		}
		$this->refreshToken();
		try {
			$url              = "https://www.exacttargetapis.com/platform/v1/endpoints/soap?access_token=" . $this->getAuthToken($this->tenantKey);
			$endpointResponse = restGet($url);
			$endpointObject   = json_decode($endpointResponse->body);
			if ($endpointObject && property_exists($endpointObject, "url")) {
				$this->endpoint = $endpointObject->url;
			}
			else {
				throw new \Exception('Unable to determine stack using /platform/v1/endpoints/:' . $endpointResponse->body);
			}
		}
		catch (Exception $e) {
			throw new \Exception('Unable to determine stack using /platform/v1/endpoints/: ' . $e->getMessage());
		}
		parent::__construct($this->xmlLoc, ['trace' => 1, 'exceptions' => 0, 'connection_timeout' => 120]);
		parent::__setLocation($this->endpoint);
	}

	public function getConfig() {
		if (file_exists(realpath(__DIR__ . "/../../ExactTargetLaravelConfig.php"))) {
			$config = include __DIR__ . "/../../ExactTargetLaravelConfig.php";
		}
		return $config;
	}

	function refreshToken($forceRefresh = false) {
		if (property_exists($this, "sdl") && $this->sdl == 0) {
			parent::__construct($this->xmlLoc, ['trace' => 1, 'exceptions' => 0]);
		}
		try {
			$currentTime = new \DateTime();
			if (is_null($this->getAuthTokenExpiration($this->tenantKey))) {
				$timeDiff = 0;
			}
			else {
				$timeDiff = $currentTime->diff($this->getAuthTokenExpiration($this->tenantKey))->format('%i');
				$timeDiff = $timeDiff + (60 * $currentTime->diff($this->getAuthTokenExpiration($this->tenantKey))->format('%H'));
			}
			if (is_null($this->getAuthToken($this->tenantKey)) || ($timeDiff < 5) || $forceRefresh) {
				$url                       = $this->tenantKey == null
					? "https://auth.exacttargetapis.com/v1/requestToken?legacy=1"
					: "https://www.exacttargetapis.com/provisioning/v1/tenants/{$this->tenantKey}/requestToken?legacy=1";
				$jsonRequest               = new \stdClass();
				$jsonRequest->clientId     = $this->clientId;
				$jsonRequest->clientSecret = $this->clientSecret;
				$jsonRequest->accessType   = "offline";
				if (!is_null($this->getRefreshToken($this->tenantKey))) {
					$jsonRequest->refreshToken = $this->getRefreshToken($this->tenantKey);
				}
				$authResponse = restPost($url, json_encode($jsonRequest));
				$authObject   = json_decode($authResponse->body);
				if ($authResponse && property_exists($authObject, "accessToken")) {
					$dv         = new \DateInterval('PT' . $authObject->expiresIn . 'S');
					$newexpTime = new \DateTime();
					$this->setAuthToken($this->tenantKey, $authObject->accessToken, $newexpTime->add($dv));
					$this->setInternalAuthToken($this->tenantKey, $authObject->legacyToken);
					if (property_exists($authObject, 'refreshToken')) {
						$this->setRefreshToken($this->tenantKey, $authObject->refreshToken);
					}
				}
				else {
					throw new \Exception('Unable to validate App Keys(ClientID/ClientSecret) provided, requestToken response:' . $authResponse->body);
				}
			}
		}
		catch (Exception $e) {
			throw new \Exception('Unable to validate App Keys(ClientID/ClientSecret) provided.: ' . $e->getMessage());
		}
	}

	function __getLastResponseHTTPCode() {
		return $this->lastHTTPCode;
	}

	function CreateWSDL($wsdlLoc) {
		try {
			$getNewWSDL = true;
			$remoteTS = $this->GetLastModifiedDate($wsdlLoc);
			if (file_exists($this->xmlLoc)) {
				$localTS = filemtime($this->xmlLoc);
				if ($remoteTS <= $localTS) {
					$getNewWSDL = false;
				}
			}
			if ($getNewWSDL) {
				$newWSDL = file_gET_contents($wsdlLoc);
				file_put_contents($this->xmlLoc, $newWSDL);
			}
		}
		catch (Exception $e) {
			throw new \Exception('Unable to store local copy of WSDL file' . "\n");
		}
	}

	function GetLastModifiedDate($remotepath) {
		$curl = curl_init($remotepath);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_NOBODY, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FILETIME, true);
		$result = curl_exec($curl);
		if ($result === false) {
			throw new \Exception(curl_error($curl));
		}
		return curl_getinfo($curl, CURLINFO_FILETIME);
	}

	function __doRequest($request, $location, $saction, $version, $one_way = 0) {
		$doc = new \DOMDocument();
		$doc->loadXML($request);
		$objWSSE = new WSSESoap($doc);
		$objWSSE->addUserToken("*", "*", false);
		$objWSSE->addOAuth($this->getInternalAuthToken($this->tenantKey));
		$content        = $objWSSE->saveXML();
		$content_length = strlen($content);
		if ($this->debugSOAP) {
			error_log('FuelSDK SOAP Request: ');
			error_log(str_replace($this->getInternalAuthToken($this->tenantKey), "REMOVED", $content));
		}
		$headers = ["Content-Type: text/xml", "SOAPAction: " . $saction, "User-Agent: " . getSDKVersion()];
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $location);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, "FuelSDK-PHP-v0.9");
		$output             = curl_exec($ch);
		$this->lastHTTPCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return $output;
	}

	public function getAuthToken($tenantKey = null) {
		$tenantKey = $tenantKey == null ? $this->tenantKey : $tenantKey;
		if ($this->tenantTokens[$tenantKey] == null) {
			$this->tenantTokens[$tenantKey] = [];
		}
		return isset($this->tenantTokens[$tenantKey]['authToken'])
			? $this->tenantTokens[$tenantKey]['authToken']
			: null;
	}

	function setAuthToken($tenantKey, $authToken, $authTokenExpiration) {
		if ($this->tenantTokens[$tenantKey] == null) {
			$this->tenantTokens[$tenantKey] = [];
		}
		$this->tenantTokens[$tenantKey]['authToken']           = $authToken;
		$this->tenantTokens[$tenantKey]['authTokenExpiration'] = $authTokenExpiration;
	}

	function getAuthTokenExpiration($tenantKey) {
		$tenantKey = $tenantKey == null ? $this->tenantKey : $tenantKey;
		if ($this->tenantTokens[$tenantKey] == null) {
			$this->tenantTokens[$tenantKey] = [];
		}
		return isset($this->tenantTokens[$tenantKey]['authTokenExpiration'])
			? $this->tenantTokens[$tenantKey]['authTokenExpiration']
			: null;
	}

	function getInternalAuthToken($tenantKey) {
		$tenantKey = $tenantKey == null ? $this->tenantKey : $tenantKey;
		if ($this->tenantTokens[$tenantKey] == null) {
			$this->tenantTokens[$tenantKey] = [];
		}
		return isset($this->tenantTokens[$tenantKey]['internalAuthToken'])
			? $this->tenantTokens[$tenantKey]['internalAuthToken']
			: null;
	}

	function setInternalAuthToken($tenantKey, $internalAuthToken) {
		if ($this->tenantTokens[$tenantKey] == null) {
			$this->tenantTokens[$tenantKey] = [];
		}
		$this->tenantTokens[$tenantKey]['internalAuthToken'] = $internalAuthToken;
	}

	function setRefreshToken($tenantKey, $refreshToken) {
		if ($this->tenantTokens[$tenantKey] == null) {
			$this->tenantTokens[$tenantKey] = [];
		}
		$this->tenantTokens[$tenantKey]['refreshToken'] = $refreshToken;
	}

	function getRefreshToken($tenantKey) {
		$tenantKey = $tenantKey == null ? $this->tenantKey : $tenantKey;
		if ($this->tenantTokens[$tenantKey] == null) {
			$this->tenantTokens[$tenantKey] = [];
		}
		return isset($this->tenantTokens[$tenantKey]['refreshToken'])
			? $this->tenantTokens[$tenantKey]['refreshToken']
			: null;
	}

	function AddSubscriberToList($emailAddress, $listIDs, $subscriberKey = null) {
		$newSub           = new ET_Subscriber;
		$newSub->authStub = $this;
		$lists            = [];
		foreach ($listIDs as $key => $value) {
			$lists[] = ["ID" => $value];
		}
		//if (is_string($emailAddress)) {
		$newSub->props = ["EmailAddress" => $emailAddress, "Lists" => $lists];
		if ($subscriberKey != null) {
			$newSub->props['SubscriberKey'] = $subscriberKey;
		}
		/*} else if (is_array($emailAddress)) {
			 $newSub->props = array();
			 for ($i = 0; $i < count($emailAddress); $i++) {
				  $copyLists = array();
				  foreach ($lists as $k => $v) {
						$NewProps = array();
						foreach($v as $prop => $value) {
							 $NewProps[$prop] = $value;
						}
						$copyLists[$k] = $NewProps;
				  }

				  $p = array("EmailAddress" => $emailAddress[$i], "Lists" => $copyLists);
				  if (is_array($subscriberKey) && $subscriberKey[$i] != null) {
						$p['SubscriberKey']  = $subscriberKey[$i];
				  }
				  $newSub->props[] = $p;
			 }
		}*/
		// Try to add the subscriber
		$postResponse = $newSub->post();
		if ($postResponse->status == false) {
			// If the subscriber already exists in the account then we need to do an update.
			// Update Subscriber On List
			if ($postResponse->results[0]->ErrorCode == "12014") {
				$patchResponse = $newSub->patch();
				return $patchResponse;
			}
		}
		return $postResponse;
	}

	function AddSubscribersToLists($subs, $listIDs) {
		//Create Lists
		foreach ($listIDs as $key => $value) {
			$lists[] = ["ID" => $value];
		}
		for ($i = 0; $i < count($subs); $i++) {
			$copyLists = [];
			foreach ($lists as $k => $v) {
				$NewProps = [];
				foreach ($v as $prop => $value) {
					$NewProps[$prop] = $value;
				}
				$copyLists[$k] = $NewProps;
			}
			$subs[$i]["Lists"] = $copyLists;
		}
		$response = new ET_Post($this, "Subscriber", $subs, true);
		return $response;
	}

	function CreateDataExtensions($dataExtensionDefinitions) {
		$newDEs           = new ET_DataExtension();
		$newDEs->authStub = $this;
		$newDEs->props    = $dataExtensionDefinitions;
		$postResponse     = $newDEs->post();
		return $postResponse;
	}

	/**
	 * @param $arrayOfTriggeredRecords ['ObjectID' => null, 'CustomerKey' => $external_key];
	 * @return \FuelSdkPhp\ET_Post
	 */
	function SendTriggeredSends($arrayOfTriggeredRecords, $subscribers) {
		$sendTS           = new ET_TriggeredSend();
		$sendTS->authStub = $this;
		$sendTS->props    = $arrayOfTriggeredRecords;
		$sendResponse     = $sendTS->send($subscribers);
		return $sendResponse;
	}

	function SendEmailToList($emailID, $listID, $sendClassficationCustomerKey) {
		$email                              = new ET_Email_SendDefinition();
		$email->props                       = ["Name" => uniqid(), "CustomerKey" => uniqid(), "Description" => "Created with FuelSDK"];
		$email->props["SendClassification"] = ["CustomerKey" => $sendClassficationCustomerKey];
		$email->props["SendDefinitionList"] = ["List" => ["ID" => $listID], "DataSourceTypeID" => "List"];
		$email->props["Email"]              = ["ID" => $emailID];
		$email->authStub                    = $this;
		$result                             = $email->post();
		if ($result->status) {
			$sendresult = $email->send();
			if ($sendresult->status) {
				$deleteresult = $email->delete();
				return $sendresult;
			}
			else {
				throw new \Exception("Unable to send using send definition due to: " . print_r($result, true));
			}
		}
		else {
			throw new \Exception("Unable to create send definition due to: " . print_r($result, true));
		}
	}

	function SendEmailToDataExtension($emailID, $sendableDataExtensionCustomerKey, $sendClassficationCustomerKey) {
		$email                              = new ET_Email_SendDefinition();
		$email->props                       = ["Name" => uniqid(), "CustomerKey" => uniqid(), "Description" => "Created with FuelSDK"];
		$email->props["SendClassification"] = ["CustomerKey" => $sendClassficationCustomerKey];
		$email->props["SendDefinitionList"] = [
			"CustomerKey"      => $sendableDataExtensionCustomerKey,
			"DataSourceTypeID" => "CustomObject"
		];
		$email->props["Email"]              = ["ID" => $emailID];
		$email->authStub                    = $this;
		$result                             = $email->post();
		if ($result->status) {
			$sendresult = $email->send();
			if ($sendresult->status) {
				$deleteresult = $email->delete();
				return $sendresult;
			}
			else {
				throw new \Exception("Unable to send using send definition due to:" . print_r($result, true));
			}
		}
		else {
			throw new \Exception("Unable to create send definition due to: " . print_r($result, true));
		}
	}

	function CreateAndStartListImport($listId, $fileName) {
		$import                                        = new ET_Import();
		$import->authStub                              = $this;
		$import->props                                 = ["Name" => "SDK Generated Import " . uniqid()];
		$import->props["CustomerKey"]                  = uniqid();
		$import->props["Description"]                  = "SDK Generated Import";
		$import->props["AllowErrors"]                  = "true";
		$import->props["DestinationObject"]            = ["ID" => $listId];
		$import->props["FieldMappingType"]             = "InferFromColumnHeadings";
		$import->props["FileSpec"]                     = $fileName;
		$import->props["FileType"]                     = "CSV";
		$import->props["RetrieveFileTransferLocation"] = ["CustomerKey" => "ExactTarget Enhanced FTP"];
		$import->props["UpdateType"]                   = "AddAndUpdate";
		$result                                        = $import->post();
		if ($result->status) {
			return $import->start();
		}
		else {
			throw new \Exception("Unable to create import definition due to: " . print_r($result, true));
		}
	}

	function CreateAndStartDataExtensionImport($dataExtensionCustomerKey, $fileName, $overwrite) {
		$import                                        = new ET_Import();
		$import->authStub                              = $this;
		$import->props                                 = ["Name" => "SDK Generated Import " . uniqid()];
		$import->props["CustomerKey"]                  = uniqid();
		$import->props["Description"]                  = "SDK Generated Import";
		$import->props["AllowErrors"]                  = "true";
		$import->props["DestinationObject"]            = ["ObjectID" => $dataExtensionCustomerKey];
		$import->props["FieldMappingType"]             = "InferFromColumnHeadings";
		$import->props["FileSpec"]                     = $fileName;
		$import->props["FileType"]                     = "CSV";
		$import->props["RetrieveFileTransferLocation"] = ["CustomerKey" => "ExactTarget Enhanced FTP"];
		if ($overwrite) {
			$import->props["UpdateType"] = "Overwrite";
		}
		else {
			$import->props["UpdateType"] = "AddAndUpdate";
		}
		$result = $import->post();
		if ($result->status) {
			return $import->start();
		}
		else {
			throw new \Exception("Unable to create import definition due to: " . print_r($result, true));
		}
	}

	function CreateProfileAttributes($allAttributes) {
		$attrs           = new ET_ProfileAttribute();
		$attrs->authStub = $this;
		$attrs->props    = $allAttributes;
		return $attrs->post();
	}

	function CreateContentAreas($arrayOfContentAreas) {
		$postC           = new ET_ContentArea();
		$postC->authStub = $this;
		$postC->props    = $arrayOfContentAreas;
		$sendResponse    = $postC->post();
		return $sendResponse;
	}
}

class ET_OEM_Client extends ET_Client {
	function CreateTenant($tenantInfo) {
		$key = $tenantInfo['key'];
		unset($tenantInfo['key']);
		$additionalQS                 = [];
		$additionalQS["access_token"] = $this->getAuthToken();
		$queryString                  = http_build_query($additionalQS);
		$completeURL                  = "https://www.exacttargetapis.com/provisioning/v1/tenants/{$key}?{$queryString}";
		return new ET_PutRest($this, $completeURL, $tenantInfo);
	}

	function GetTenants() {
		$additionalQS                 = [];
		$additionalQS["access_token"] = $this->getAuthToken();
		$queryString                  = http_build_query($additionalQS);
		$completeURL                  = "https://www.exacttargetapis.com/provisioning/v1/tenants/?{$queryString}";
		return new ET_GetRest($this, $completeURL, $queryString);
	}
}

class ET_Constructor {
	public $status, $code, $message, $results, $request_id, $moreResults, $errors;

	function __construct($requestresponse, $httpcode, $restcall = false) {
		$this->code = $httpcode;
		if (!$restcall) {
			if (is_soap_fault($requestresponse)) {
				$this->status  = false;
				$this->message = "SOAP Fault: (faultcode: {$requestresponse->faultcode}, faultstring: {$requestresponse->faultstring})";
				$this->message = "{$requestresponse->faultcode} {$requestresponse->faultstring})";
			}
			else {
				$this->status = true;
			}
		}
		else {
			if ($this->code != 200 && $this->code != 201 && $this->code != 202) {
				$this->status = false;
			}
			else {
				$this->status = true;
			}
			if (json_decode($requestresponse) != null) {
				$this->results = json_decode($requestresponse);
			}
			else {
				$this->message = $requestresponse;
			}
		}
	}
}

class ET_Get extends ET_Constructor {
	// these properties cause errors if present (when using ET_Info)
	private $skip_properties = ['isPlatformObject', 'DataRetentionPeriod', 'Client.ClientPartnerKey'];

	function __construct($authStub, $objType, $props, $filter, $getSinceLastBatch = false) {
		$authStub->refreshToken();
		$rrm             = [];
		$request         = [];
		$retrieveRequest = [];
		// If Props is not sent then Info will be used to find all retrievable properties
		if (is_null($props)) {
			$props = [];
			$info  = new ET_Info($authStub, $objType);
			if (is_array($info->results)) {
				foreach ($info->results as $property) {
					if ($property->IsRetrievable && !in_array($property->Name, $this->skip_properties)) {
						if ($objType == 'FilterDefinition') {
							if ($property->Name == "Owner.ID") {
								continue;
							}
							$a = strpos($property->Name, 'DataSource.');
							if (strpos($property->Name, 'DataSource.') !== false) {
								continue;
							}
						}
						$props[] = $property->Name;
					}
				}
			}
		}
		if (isAssoc($props)) {
			$retrieveProps = [];
			foreach ($props as $key => $value) {
				if (!is_array($value)) {
					$retrieveProps[] = $key;
				}
				$retrieveRequest["Properties"] = $retrieveProps;
			}
		}
		else {
			$retrieveRequest["Properties"] = $props;
		}
		$retrieveRequest["ObjectType"] = $objType;
		if ("Account" == $objType) {
			$retrieveRequest["QueryAllAccounts"] = true;
		}
		if (property_exists($authStub, 'BusinessUnit')) {
			$retrieveRequest["ClientIDs"] = $authStub->BusinessUnit;
		}
		if ($objType == 'Automation') {
			$retrieveRequest["Properties"][] = 'ProgramID';
		}

		if ($filter) {
			if (array_key_exists("LogicalOperator", $filter)) {
				$cfp                       = new \stdClass();
				$cfp->LeftOperand          = new \SoapVar($filter["LeftOperand"], SOAP_ENC_OBJECT, 'SimpleFilterPart',
				                                          "http://exacttarget.com/wsdl/partnerAPI");
				$cfp->RightOperand         = new \SoapVar($filter["RightOperand"], SOAP_ENC_OBJECT, 'SimpleFilterPart',
				                                          "http://exacttarget.com/wsdl/partnerAPI");
				$cfp->LogicalOperator      = $filter["LogicalOperator"];
				$retrieveRequest["Filter"] = new \SoapVar($cfp, SOAP_ENC_OBJECT, 'ComplexFilterPart',
				                                          "http://exacttarget.com/wsdl/partnerAPI");
			}
			else {
				$retrieveRequest["Filter"] = new \SoapVar($filter, SOAP_ENC_OBJECT, 'SimpleFilterPart',
				                                          "http://exacttarget.com/wsdl/partnerAPI");
			}
		}
		if ($getSinceLastBatch) {
			$retrieveRequest["RetrieveAllSinceLastBatch"] = true;
		}

//		if ($objType == 'Email') {
//			// $retrieveRequest["Properties"][] = 'Template';
//
//			Log::debug('Get Email Properties: ', [$retrieveRequest["Properties"]]);
//		}
		$request["RetrieveRequest"] = $retrieveRequest;
		$rrm["RetrieveRequestMsg"]  = $request;

		$return                     = $authStub->__soapCall("Retrieve", $rrm, null, null, $out_header);
//		Log::debug('Soap Request: ', [$authStub->__getLastRequest()]);
//		Log::debug('Soap Response: ', [$authStub->__getLastResponse()]);

		parent::__construct($return, $authStub->__getLastResponseHTTPCode());
		if ($this->status) {
			if (property_exists($return, "Results")) {
				// We always want the results property when doing a retrieve to be an array
				if (is_array($return->Results)) {
					$this->results = $return->Results;
				}
				else {
					$this->results = [$return->Results];
				}
			}
			else {
				$this->results = [];
			}
			if ($return->OverallStatus != "OK" && $return->OverallStatus != "MoreDataAvailable") {
				$this->status  = false;
				$this->message = $return->OverallStatus;
				$this->props   = $props;
			}
			$this->moreResults = false;
			if ($return->OverallStatus == "MoreDataAvailable") {
				$this->moreResults = true;
			}
			$this->request_id = $return->RequestID;
		}
		$this->errors[] = 'ET Error: ';//  . $return->
	}
}

class ET_Continue extends ET_Constructor {
	function __construct($authStub, $request_id) {
		$authStub->refreshToken();
		$rrm                                = [];
		$request                            = [];
		$retrieveRequest                    = [];
		$retrieveRequest["ContinueRequest"] = $request_id;
		$retrieveRequest["ObjectType"]      = null;
		if (property_exists($authStub, 'BusinessUnit')) {
			$retrieveRequest["ClientIDs"] = $authStub->BusinessUnit;
		}
		$request["RetrieveRequest"] = $retrieveRequest;
		$rrm["RetrieveRequestMsg"]  = $request;
//		Log::critical('AuthStub', [$authStub]);
		$return = $authStub->__soapCall("Retrieve", $rrm, null, null, $out_header);
		parent::__construct($return, $authStub->__getLastResponseHTTPCode());
		if ($this->status) {
			if (property_exists($return, "Results")) {
				// We always want the results property when doing a retrieve to be an array
				if (is_array($return->Results)) {
					$this->results = $return->Results;
				}
				else {
					$this->results = [$return->Results];
				}
			}
			else {
				$this->results = [];
			}
			$this->moreResults = false;
			if ($return->OverallStatus == "MoreDataAvailable") {
				$this->moreResults = true;
			}
			if ($return->OverallStatus != "OK" && $return->OverallStatus != "MoreDataAvailable") {
				$this->status  = false;
				$this->message = $return->OverallStatus;
			}
			$this->request_id = $return->RequestID;
		}
	}
}

/**
 * A generic getter to discover names of properties on an et object
 * Class ET_Info
 * @package FuelSdkPhp
 */
class ET_Info extends ET_Constructor {
	/**
	 * ET_Info constructor.
	 * @param $authStub
	 * @param $objType
	 * @param bool $extended
	 */
	function __construct($authStub, $objType, $extended = false) {
		$authStub->refreshToken();
		$drm                                        = [];
		$request                                    = [];
		$describeRequest                            = [];
		$describeRequest["ObjectDefinitionRequest"] = ["ObjectType" => $objType];
		$request["DescribeRequests"]                = $describeRequest;
		$drm["DefinitionRequestMsg"]                = $request;
		$return                                     = $authStub->__soapCall("Describe", $drm, null, null, $out_header);
		parent::__construct($return, $authStub->__getLastResponseHTTPCode());
		if ($this->status) {
			if (property_exists($return, 'ObjectDefinition') && property_exists($return->ObjectDefinition, "Properties")) {
				if ($extended) {
					$this->results = $return->ObjectDefinition->ExtendedProperties->ExtendedProperty;
				}
				else {
					$this->results = $return->ObjectDefinition->Properties;
				}
			}
			else {
				$this->status = false;
			}
		}
	}
}

class ET_Post extends ET_Constructor {
	function __construct($authStub, $objType, $props, $upsert = false) {
		$authStub->refreshToken();
		$cr      = [];
		$objects = [];
		if (isAssoc($props)) {
			$objects["Objects"] = new \SoapVar($props, SOAP_ENC_OBJECT, $objType,
			                                   "http://exacttarget.com/wsdl/partnerAPI");
		}
		else {
			$objects["Objects"] = [];
			foreach ($props as $object) {
				$objects["Objects"][] = new \SoapVar($object, SOAP_ENC_OBJECT, $objType,
				                                     "http://exacttarget.com/wsdl/partnerAPI");
			}
		}
		if ($upsert) {
			$objects["Options"] = [
				'SaveOptions' => [
					'SaveOption' => [
						'PropertyName' => '*',
						'SaveAction'   => 'UpdateAdd'
					]
				]
			];
		}
		else {
			$objects["Options"] = "";
		}
		$cr["CreateRequest"] = $objects;
		// Log::critical('Post: ', $cr);
		try {
			$return = $authStub->__soapCall("Create", $cr, null, null, $out_header);
		}
		catch (\SoapFault $e) {
			dd('Soap Fault with ' . $e - faultstring, $e);
		}
		parent::__construct($return, $authStub->__getLastResponseHTTPCode());
		if ($this->status) {
			if (property_exists($return, "Results")) {
				// We always want the results property when doing a retrieve to be an array
				if (is_array($return->Results)) {
					$this->results = $return->Results;
				}
				else {
					$this->results = [$return->Results];
				}
			}
			else {
				$this->status = false;
			}
			if ($return->OverallStatus != "OK") {
				$this->status = false;
			}
		}
	}
}

class ET_Patch extends ET_Constructor {
	function __construct($authStub, $objType, $props, $upsert = false) {
		$authStub->refreshToken();
		$cr                 = [];
		$objects            = [];
		$object             = $props;
		$objects["Objects"] = new \SoapVar($props, SOAP_ENC_OBJECT, $objType, "http://exacttarget.com/wsdl/partnerAPI");
		if ($upsert) {
			$objects["Options"] = [
				'SaveOptions' => [
					'SaveOption' => [
						'PropertyName' => '*',
						'SaveAction'   => 'UpdateAdd'
					]
				]
			];
		}
		else {
			$objects["Options"] = "";
		}
		$cr["UpdateRequest"] = $objects;
		$return              = $authStub->__soapCall("Update", $cr, null, null, $out_header);
		parent::__construct($return, $authStub->__getLastResponseHTTPCode());
		if ($this->status) {
			if (property_exists($return, "Results")) {
				// We always want the results property when doing a retrieve to be an array
				if (is_array($return->Results)) {
					$this->results = $return->Results;
				}
				else {
					$this->results = [$return->Results];
				}
			}
			else {
				$this->status = false;
			}
			if ($return->OverallStatus != "OK") {
				$this->status = false;
			}
		}
	}
}

class ET_Delete extends ET_Constructor {
	function __construct($authStub, $objType, $props) {
		$authStub->refreshToken();
		$cr                  = [];
		$objects             = [];
		$object              = $props;
		$objects["Objects"]  = new \SoapVar($props, SOAP_ENC_OBJECT, $objType, "http://exacttarget.com/wsdl/partnerAPI");
		$objects["Options"]  = "";
		$cr["DeleteRequest"] = $objects;
		$return              = $authStub->__soapCall("Delete", $cr, null, null, $out_header);
		parent::__construct($return, $authStub->__getLastResponseHTTPCode());
		if ($this->status) {
			if (property_exists($return, "Results")) {
				// We always want the results property when doing a retrieve to be an array
				if (is_array($return->Results)) {
					$this->results = $return->Results;
				}
				else {
					$this->results = [$return->Results];
				}
			}
			else {
				$this->status = false;
			}
			if ($return->OverallStatus != "OK") {
				$this->status = false;
			}
		}
	}
}

class ET_Configure extends ET_Constructor {
	function __construct($authStub, $objType, $action, $props) {
		$authStub->refreshToken();
		$configure                          = [];
		$configureRequest                   = [];
		$configureRequest['Action']         = $action;
		$configureRequest['Configurations'] = [];
		if (!isAssoc($props)) {
			foreach ($props as $value) {
				$configureRequest['Configurations'][] = new \SoapVar($value, SOAP_ENC_OBJECT, $objType,
				                                                     "http://exacttarget.com/wsdl/partnerAPI");
			}
		}
		else {
			$configureRequest['Configurations'][] = new \SoapVar($props, SOAP_ENC_OBJECT, $objType,
			                                                     "http://exacttarget.com/wsdl/partnerAPI");
		}
		$configure['ConfigureRequestMsg'] = $configureRequest;
		$return                           = $authStub->__soapCall("Configure", $configure, null, null, $out_header);
		parent::__construct($return, $authStub->__getLastResponseHTTPCode());
		if ($this->status) {
			if (property_exists($return->Results, "Result")) {
				if (is_array($return->Results->Result)) {
					$this->results = $return->Results->Result;
				}
				else {
					$this->results = [$return->Results->Result];
				}
				if ($return->OverallStatus != "OK") {
					$this->status = false;
				}
			}
			else {
				$this->status = false;
			}
		}
	}
}

class ET_Perform extends ET_Constructor {
	function __construct($authStub, $objType, $action, $props) {
		$authStub->refreshToken();
		$perform                         = [];
		$performRequest                  = [];
		$performRequest['Action']        = $action;
		$performRequest['Definitions']   = [];
		$performRequest['Definitions'][] = new \SoapVar($props, SOAP_ENC_OBJECT, $objType,
		                                                "http://exacttarget.com/wsdl/partnerAPI");
		$perform['PerformRequestMsg']    = $performRequest;
		$return                          = $authStub->__soapCall("Perform", $perform, null, null, $out_header);
		parent::__construct($return, $authStub->__getLastResponseHTTPCode());
		if ($this->status) {
			if (property_exists($return->Results, "Result")) {
				if (is_array($return->Results->Result)) {
					$this->results = $return->Results->Result;
				}
				else {
					$this->results = [$return->Results->Result];
				}
				if ($return->OverallStatus != "OK") {
					$this->status = false;
				}
			}
			else {
				$this->status = false;
			}
		}
	}
}

class ET_GetSupportRest extends ET_BaseObjectRest {
	protected $lastPageNumber;

	public function get() {
		$this->authStub->refreshToken();
		$completeURL  = $this->endpoint;
		$additionalQS = [];
		if (!is_null($this->props)) {
			foreach ($this->props as $key => $value) {
				if (in_array($key, $this->urlProps)) {
					$completeURL = str_replace("{{$key}}", $value, $completeURL);
				}
				else {
					$additionalQS[$key] = $value;
				}
			}
		}
		foreach ($this->urlPropsRequired as $value) {
			if (is_null($this->props) || in_array($value, $this->props)) {
				throw new \Exception("Unable to process request due to missing required prop: {$value}");
			}
		}
		// Clean up not required URL parameters
		foreach ($this->urlProps as $value) {
			$completeURL = str_replace("{{$value}}", "", $completeURL);
		}
		$additionalQS["access_token"] = $this->authStub->getAuthToken();
		$queryString                  = http_build_query($additionalQS);
		$completeURL                  = "{$completeURL}?{$queryString}";
		$response                     = new ET_GetRest($this->authStub, $completeURL, $queryString);
		if (property_exists($response->results, 'page')) {
			$this->lastPageNumber = $response->results->page;
			$pageSize             = $response->results->pageSize;
			$count                = null;
			if (property_exists($response->results, 'count')) {
				$count = $response->results->count;
			}
			else {
				if (property_exists($response->results, 'totalCount')) {
					$count = $response->results->totalCount;
				}
			}
			if ($count && ($count > ($this->lastPageNumber * $pageSize))) {
				$response->moreResults = true;
			}
		}
		return $response;
	}

	public function getMoreResults() {
		$originalPageValue   = 1;
		$removePageFromProps = false;
		if ($this->props && array_key_exists($this->props, '$page')) {
			$originalPageValue = $this->props['page'];
		}
		else {
			$removePageFromProps = true;
		}
		if (!$this->props) {
			$this->props = [];
		}
		$this->props['$page'] = $this->lastPageNumber + 1;
		$response             = $this->get();
		if ($removePageFromProps) {
			unset($this->props['$page']);
		}
		else {
			$this->props['$page'] = $originalPageValue;
		}
		return $response;
	}
}

class ET_CUDSupportRest extends ET_GetSupportRest {
	protected $folderProperty, $folderMediaType;

	public function post() {
		$this->authStub->refreshToken();
		$completeURL  = $this->endpoint;
		$additionalQS = [];
		if (!is_null($this->props)) {
			foreach ($this->props as $key => $value) {
				if (in_array($key, $this->urlProps)) {
					$completeURL = str_replace("{{$key}}", $value, $completeURL);
				}
			}
		}
		foreach ($this->urlPropsRequired as $value) {
			if (is_null($this->props) || in_array($value, $this->props)) {
				throw new \Exception("Unable to process request due to missing required prop: {$value}");
			}
		}
		// Clean up not required URL parameters
		foreach ($this->urlProps as $value) {
			$completeURL = str_replace("{{$value}}", "", $completeURL);
		}
		$additionalQS["access_token"] = $this->authStub->getAuthToken();
		$queryString                  = http_build_query($additionalQS);
		$completeURL                  = "{$completeURL}?{$queryString}";
		$response                     = new ET_PostRest($this->authStub, $completeURL, $this->props);
		return $response;
	}

	public function patch() {
		$this->authStub->refreshToken();
		$completeURL  = $this->endpoint;
		$additionalQS = [];
		// All URL Props are required when doing Patch
		foreach ($this->urlProps as $value) {
			if (is_null($this->props) || !array_key_exists($value, $this->props)) {
				throw new \Exception("Unable to process request due to missing required prop: {$value}");
			}
		}
		if (!is_null($this->props)) {
			foreach ($this->props as $key => $value) {
				if (in_array($key, $this->urlProps)) {
					$completeURL = str_replace("{{$key}}", $value, $completeURL);
				}
			}
		}
		$additionalQS["access_token"] = $this->authStub->getAuthToken();
		$queryString                  = http_build_query($additionalQS);
		$completeURL                  = "{$completeURL}?{$queryString}";
		$response                     = new ET_PatchRest($this->authStub, $completeURL, $this->props);
		return $response;
	}

	public function delete() {
		$this->authStub->refreshToken();
		$completeURL  = $this->endpoint;
		$additionalQS = [];
		// All URL Props are required when doing Delete
		foreach ($this->urlProps as $value) {
			if (is_null($this->props) || !array_key_exists($value, $this->props)) {
				throw new \Exception("Unable to process request due to missing required prop: {$value}");
			}
		}
		if (!is_null($this->props)) {
			foreach ($this->props as $key => $value) {
				if (in_array($key, $this->urlProps)) {
					$completeURL = str_replace("{{$key}}", $value, $completeURL);
				}
			}
		}
		$additionalQS["access_token"] = $this->authStub->getAuthToken();
		$queryString                  = http_build_query($additionalQS);
		$completeURL                  = "{$completeURL}?{$queryString}";
		$response                     = new ET_DeleteRest($this->authStub, $completeURL);
		return $response;
	}
}

class ET_GetRest extends ET_Constructor {
	function __construct($authStub, $url, $qs = null) {
		$restResponse      = restGet($url);
		$this->moreResults = false;
		parent::__construct($restResponse->body, $restResponse->httpcode, true);
	}
}

class ET_PostRest extends ET_Constructor {
	function __construct($authStub, $url, $props) {
		$restResponse = restPost($url, json_encode($props));
		parent::__construct($restResponse->body, $restResponse->httpcode, true);
	}
}

class ET_DeleteRest extends ET_Constructor {
	function __construct($authStub, $url) {
		$restResponse = restDelete($url);
		parent::__construct($restResponse->body, $restResponse->httpcode, true);
	}
}

class ET_PatchRest extends ET_Constructor {
	function __construct($authStub, $url, $props) {
		$restResponse = restPatch($url, json_encode($props));
		parent::__construct($restResponse->body, $restResponse->httpcode, true);
	}
}

class ET_PutRest extends ET_Constructor {
	function __construct($authStub, $url, $props) {
		$restResponse = restPut($url, json_encode($props));
		parent::__construct($restResponse->body, $restResponse->httpcode, true);
	}
}

class ET_Campaign extends ET_CUDSupportRest {
	function __construct() {
		$this->endpoint         = "https://www.exacttargetapis.com/hub/v1/campaigns/{id}";
		$this->urlProps         = ["id"];
		$this->urlPropsRequired = [];
	}
}

class ET_Campaign_Asset extends ET_CUDSupportRest {
	function __construct() {
		$this->endpoint         = "https://www.exacttargetapis.com/hub/v1/campaigns/{id}/assets/{assetId}";
		$this->urlProps         = ["id", "assetId"];
		$this->urlPropsRequired = ["id"];
	}
}

class ET_Message_Guide extends ET_CUDSupportRest {
	function __construct() {
		$this->endpoint         = "https://www.exacttargetapis.com/guide/v1/messages/{id}";
		$this->urlProps         = ["id"];
		$this->urlPropsRequired = [];
	}

	function get() {
		$origEndpoint = $this->endpoint;
		$origProps    = $this->urlProps;
		if (count($this->props) == 0) {
			$this->endpoint = "https://www.exacttargetapis.com/guide/v1/messages/f:@all";
		}
		elseif (array_key_exists('key', $this->props)) {
			$this->endpoint = "https://www.exacttargetapis.com/guide/v1/messages/key:{key}";
			$this->urlProps = ["key"];
		}
		$response       = parent::get();
		$this->endpoint = $origEndpoint;
		$this->urlProps = $origProps;
		return $response;
	}

	function convert() {
		$completeURL = "https://www.exacttargetapis.com/guide/v1/messages/convert?access_token=" . $this->authStub->getAuthToken();
		$response    = new ET_PostRest($this->authStub, $completeURL, $this->props);
		return $response;
	}

	function sendProcess() {
		$renderMG           = new ET_Message_Guide();
		$renderMG->authStub = $this->authStub;
		$renderMG->props    = ["id" => $this->props['messageID']];
		$renderResult       = $renderMG->render();
		if (!$renderResult->status) {
			return $renderResult;
		}
		$html          = $renderResult->results->emailhtmlbody;
		$send          = [];
		$send["Email"] = ["Subject" => $this->props['subject'], "HTMLBody" => $html];
		$send["List"]  = ["ID" => $this->props['listID']];
		$response      = new ET_Post($this->authStub, "Send", $send);
		return $response;
	}

	function render() {
		$completeURL = null;
		$response    = null;
		if (is_array($this->props) && array_key_exists("id", $this->props)) {
			$completeURL = "https://www.exacttargetapis.com/guide/v1/messages/render/{$this->props['id']}?access_token=" . $this->authStub->getAuthToken();
			$response    = new ET_GetRest($this->authStub, $completeURL, null);
		}
		else {
			$completeURL = "https://www.exacttargetapis.com/guide/v1/messages/render?access_token=" . $this->authStub->getAuthToken();
			$response    = new ET_PostRest($this->authStub, $completeURL, $this->props);
		}
		return $response;
	}
}

class ET_Portfolio extends ET_CUDSupport {
	function __construct() {
		$this->obj = "Portfolio";
	}
}

class ET_Asset extends ET_CUDSupportRest {
	function __construct() {
		$this->endpoint         = "https://www.exacttargetapis.com/guide/v1/contentItems/portfolio/{id}";
		$this->urlProps         = ["id"];
		$this->urlPropsRequired = [];
	}

	public function upload() {
		$completeURL = "https://www.exacttargetapis.com/guide/v1/contentItems/portfolio/fileupload?access_token=" . $this->authStub->getAuthToken();
		$post        = ['file_contents' => '@' . $this->attrs['filePath']];
		$ch          = curl_init();
		$headers     = ["User-Agent: " . getSDKVersion()];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_URL, $completeURL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		// Disable VerifyPeer for SSL
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$outputJSON = curl_exec($ch);
		curl_close($ch);
		$responseObject           = new \stdClass();
		$responseObject->body     = $outputJSON;
		$responseObject->httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		return $responseObject;
	}

	public function patch() {
		return null;
	}

	public function delete() {
		return null;
	}
}

class ET_BaseObject {
	public $authStub, $props, $filter, $organizationId, $organizationKey;
	protected                                           $obj, $lastRequestID;
}

class ET_BaseObjectRest {
	public $authStub, $props, $organizationId, $organizationKey;
	protected                                  $endpoint, $urlProps, $urlPropsRequired;
}

class ET_GetSupport extends ET_BaseObject {
	public function get() {
		$lastBatch = false;
		if (property_exists($this, 'getSinceLastBatch')) {
			$lastBatch = $this->getSinceLastBatch;
		}
		$response            = new ET_Get($this->authStub, $this->obj, $this->props, $this->filter, $lastBatch);
		$this->lastRequestID = $response->request_id;
		return $response;
	}

	public function getMoreResults() {
		$response            = new ET_Continue($this->authStub, $this->lastRequestID);
		$this->lastRequestID = $response->request_id;
		return $response;
	}

	public function info() {
		$response = new ET_Info($this->authStub, $this->obj);
		return $response;
	}
}

class ET_CUDSupport extends ET_GetSupport {
	public function post() {
		$originalProps = $this->props;
		if (property_exists($this, 'folderProperty') && !is_null($this->folderProperty) && !is_null($this->folderId)) {
			$this->props[$this->folderProperty] = $this->folderId;
		}
		else {
			if (property_exists($this, 'folderProperty') && !is_null($this->authStub->packageName)) {
				if (is_null($this->authStub->packageFolders)) {
					$getPackageFolder           = new ET_Folder();
					$getPackageFolder->authStub = $this->authStub;
					$getPackageFolder->props    = ["ID", "ContentType"];
					$getPackageFolder->filter   = [
						"Property"       => "Name",
						"SimpleOperator" => "equals",
						"Value"          => $this->authStub->packageName
					];
					$resultPackageFolder        = $getPackageFolder->get();
					if ($resultPackageFolder->status) {
						$this->authStub->packageFolders = [];
						foreach ($resultPackageFolder->results as $result) {
							$this->authStub->packageFolders[$result->ContentType] = $result->ID;
						}
					}
					else {
						throw new \Exception('Unable to retrieve folders from account due to: ' . $resultPackageFolder->message);
					}
				}
				if (!array_key_exists($this->folderMediaType, $this->authStub->packageFolders)) {
					if (is_null($this->authStub->parentFolders)) {
						$parentFolders           = new ET_Folder();
						$parentFolders->authStub = $this->authStub;
						$parentFolders->props    = ["ID", "ContentType"];
						$parentFolders->filter   = [
							"Property"       => "ParentFolder.ID",
							"SimpleOperator" => "equals",
							"Value"          => "0"
						];
						$resultParentFolders     = $parentFolders->get();
						if ($resultParentFolders->status) {
							$this->authStub->parentFolders = [];
							foreach ($resultParentFolders->results as $result) {
								$this->authStub->parentFolders[$result->ContentType] = $result->ID;
							}
						}
						else {
							throw new \Exception('Unable to retrieve folders from account due to: ' . $resultParentFolders->message);
						}
					}
					$newFolder           = new ET_Folder();
					$newFolder->authStub = $this->authStub;
					$newFolder->props    = [
						"Name"         => $this->authStub->packageName,
						"Description"  => $this->authStub->packageName,
						"ContentType"  => $this->folderMediaType,
						"IsEditable"   => "true",
						"ParentFolder" => ["ID" => $this->authStub->parentFolders[$this->folderMediaType]]
					];
					$folderResult        = $newFolder->post();
					if ($folderResult->status) {
						$this->authStub->packageFolders[$this->folderMediaType] = $folderResult->results[0]->NewID;
					}
					else {
						throw new \Exception('Unable to create folder for Post due to: ' . $folderResult->message);
					}
				}
				$this->props[$this->folderProperty] = $this->authStub->packageFolders[$this->folderMediaType];
			}
		}
		// dump([$this->authStub, $this->obj, $this->props]);
		$response    = new ET_Post($this->authStub, $this->obj, $this->props);
		$this->props = $originalProps;
		return $response;
	}

	public function patch() {
		$originalProps = $this->props;
		if (property_exists($this, 'folderProperty') && !is_null($this->folderProperty) && !is_null($this->folderId)) {
			$this->props[$this->folderProperty] = $this->folderId;
		}
		$response    = new ET_Patch($this->authStub, $this->obj, $this->props);
		$this->props = $originalProps;
		return $response;
	}

	public function delete() {
		$response = new ET_Delete($this->authStub, $this->obj, $this->props);
		return $response;
	}
}

class ET_CUDWithUpsertSupport extends ET_CUDSupport {
	public function put() {
		$originalProps = $this->props;
		if (property_exists($this, 'folderProperty') && !is_null($this->folderProperty) && !is_null($this->folderId)) {
			$this->props[$this->folderProperty] = $this->folderId;
		}
		$response    = new ET_Patch($this->authStub, $this->obj, $this->props, true);
		$this->props = $originalProps;
		return $response;
	}
}

class ET_Subscriber extends ET_CUDWithUpsertSupport {
	function __construct() {
		$this->obj = "Subscriber";
	}
}

class ET_DataExtension extends ET_CUDSupport {
	public $columns;

	function __construct() {
		$this->obj = "DataExtension";
	}

	public function post() {
		$originalProps = $this->props;
		if (isAssoc($this->props)) {
			$this->props["Fields"] = ["Field" => []];
			if (!is_null($this->columns) && is_array($this->columns)) {
				foreach ($this->columns as $column) {
					array_push($this->props['Fields']['Field'], $column);
				}
			}
		}
		else {
			$newProps = [];
			foreach ($this->props as $DE) {
				$newDE           = $DE;
				$newDE["Fields"] = ["Field" => []];
				if (!is_null($DE['columns']) && is_array($DE['columns'])) {
					foreach ($DE['columns'] as $column) {
						array_push($newDE['Fields']['Field'], $column);
					}
				}
				array_push($newProps, $newDE);
			}
			$this->props = $newProps;
		}
		$response    = parent::post();
		$this->props = $originalProps;
		return $response;
	}

	public function patch() {
		$this->props["Fields"] = ["Field" => []];
		foreach ($this->columns as $column) {
			array_push($this->props['Fields']['Field'], $column);
		}
		$response = parent::patch();
		unset($this->props["Fields"]);
		return $response;
	}
}

class ET_DataExtension_Column extends ET_GetSupport {
	function __construct() {
		$this->obj             = "DataExtensionField";
		$this->folderProperty  = "CategoryID";
		$this->folderMediaType = "dataextension";
	}

	public function get() {
		$fixCustomerKey = false;
		if ($this->filter && array_key_exists('Property', $this->filter) && $this->filter['Property'] == "CustomerKey"
		) {
			$this->filter['Property'] = "DataExtension.CustomerKey";
			$fixCustomerKey           = true;
		}
		$response = parent::get();
		if ($fixCustomerKey) {
			$this->filter['Property'] = "CustomerKey";
		}
		return $response;
	}
}

class ET_DataExtension_Row extends ET_CUDWithUpsertSupport {
	public $Name, $CustomerKey;

	function __construct() {
		$this->obj = "DataExtensionObject";
	}

	public function get() {
		$this->getName();
		$this->obj = "DataExtensionObject[" . $this->Name . "]";
		$response  = parent::get();
		$this->obj = "DataExtensionObject";
		return $response;
	}

	public function post() {
		$this->getCustomerKey();
		$originalProps = $this->props;
		$overrideProps = [];
		$fields        = [];
		foreach ($this->props as $key => $value) {
			$fields[] = ["Name" => $key, "Value" => $value];
		}
		$overrideProps['CustomerKey'] = $this->CustomerKey;
		$overrideProps['Properties']  = ["Property" => $fields];
		$this->props                  = $overrideProps;
		$response                     = parent::post();
		$this->props                  = $originalProps;
		return $response;
	}

	public function patch() {
		$this->getCustomerKey();
		$originalProps = $this->props;
		$overrideProps = [];
		$fields        = [];
		foreach ($this->props as $key => $value) {
			$fields[] = ["Name" => $key, "Value" => $value];
		}
		$overrideProps['CustomerKey'] = $this->CustomerKey;
		$overrideProps['Properties']  = ["Property" => $fields];
		$this->props                  = $overrideProps;
		$response                     = parent::patch();
		$this->props                  = $originalProps;
		return $response;
	}

	public function delete() {
		$this->getCustomerKey();
		$originalProps = $this->props;
		$overrideProps = [];
		$fields        = [];
		foreach ($this->props as $key => $value) {
			$fields[] = ["Name" => $key, "Value" => $value];
		}
		$overrideProps['CustomerKey'] = $this->CustomerKey;
		$overrideProps['Keys']        = ["Key" => $fields];
		$this->props                  = $overrideProps;
		$response                     = parent::delete();
		$this->props                  = $originalProps;
		return $response;
	}

	private function getName() {
		if (is_null($this->Name)) {
			if (is_null($this->CustomerKey)) {
				throw new \Exception('Unable to process request due to CustomerKey and Name not being defined on ET_DataExtension_Row');
			}
			else {
				$nameLookup           = new ET_DataExtension();
				$nameLookup->authStub = $this->authStub;
				$nameLookup->props    = ["Name", "CustomerKey"];
				$nameLookup->filter   = [
					'Property'       => 'CustomerKey',
					'SimpleOperator' => 'equals',
					'Value'          => $this->CustomerKey
				];
				$nameLookupGet        = $nameLookup->get();
				if ($nameLookupGet->status && count($nameLookupGet->results) == 1) {
					$this->Name = $nameLookupGet->results[0]->Name;
				}
				else {
					throw new \Exception('Unable to process request due to unable to find DataExtension based on CustomerKey');
				}
			}
		}
	}

	private function getCustomerKey() {
		if (is_null($this->CustomerKey)) {
			if (is_null($this->Name)) {
				throw new \Exception('Unable to process request due to CustomerKey and Name not being defined on ET_DataExtension_Row');
			}
			else {
				$nameLookup           = new ET_DataExtension();
				$nameLookup->authStub = $this->authStub;
				$nameLookup->props    = ["Name", "CustomerKey"];
				$nameLookup->filter   = ['Property' => 'Name', 'SimpleOperator' => 'equals', 'Value' => $this->Name];
				$nameLookupGet        = $nameLookup->get();
				if ($nameLookupGet->status && count($nameLookupGet->results) == 1) {
					$this->CustomerKey = $nameLookupGet->results[0]->CustomerKey;
				}
				else {
					throw new \Exception('Unable to process request due to unable to find DataExtension based on Name "' . $this->Name . '"');
				}
			}
		}
	}
}

class ET_DataExtensionTemplate extends ET_GetSupport {
	function __construct() {
		$this->obj = "DataExtensionTemplate";
	}
}

class ET_Group extends ET_CUDSupport {
	function __construct() {
		$this->obj = "Group";
	}
}

class ET_ContentArea extends ET_CUDSupport {
	public $folderId;

	function __construct() {
		$this->obj = "ContentArea";
//		$this->folderProperty  = "CategoryID";
		$this->folderMediaType = "content";
	}
}

class ET_Email extends ET_CUDSupport {
	public $folderId;

	function __construct() {
		$this->obj             = "Email";
		$this->folderProperty  = "CategoryID";
		$this->folderMediaType = "email";
	}
}

class ET_Automation extends ET_CUDSupport {
	function __construct() {
		$this->obj = "Automation";
	}
}

class ET_Email_SendDefinition extends ET_CUDSupport {
	public $folderId, $lastTaskID;

	function __construct() {
		$this->obj             = "EmailSendDefinition";
		$this->folderProperty  = "CategoryID";
		$this->folderMediaType = "userinitiatedsends";
	}

	function send() {
		$originalProps = $this->props;
		$response      = new ET_Perform($this->authStub, $this->obj, 'start', $this->props);
		if ($response->status) {
			$this->lastTaskID = $response->results[0]->Task->ID;
		}
		$this->props = $originalProps;
		return $response;
	}

	function status() {
		$this->filter        = ['Property' => 'ID', 'SimpleOperator' => 'equals', 'Value' => $this->lastTaskID];
		$response            = new ET_Get($this->authStub, 'Send', [
			'ID',
			'CreatedDate',
			'ModifiedDate',
			'Client.ID',
			'Email.ID',
			'SendDate',
			'FromAddress',
			'FromName',
			'Duplicates',
			'InvalidAddresses',
			'ExistingUndeliverables',
			'ExistingUnsubscribes',
			'HardBounces',
			'SoftBounces',
			'OtherBounces',
			'ForwardedEmails',
			'UniqueClicks',
			'UniqueOpens',
			'NumberSent',
			'NumberDelivered',
			'NumberTargeted',
			'NumberErrored',
			'NumberExcluded',
			'Unsubscribes',
			'MissingAddresses',
			'Subject',
			'PreviewURL',
			'SentDate',
			'EmailName',
			'Status',
			'IsMultipart',
			'SendLimit',
			'SendWindowOpen',
			'SendWindowClose',
			'BCCEmail',
			'EmailSendDefinition.ObjectID',
			'EmailSendDefinition.CustomerKey'
		], $this->filter);
		$this->lastRequestID = $response->request_id;
		return $response;
	}
}

class ET_Import extends ET_CUDSupport {
	public $lastTaskID;

	function __construct() {
		$this->obj = "ImportDefinition";
	}

	function post() {
		$originalProp = $this->props;
		# If the ID property is specified for the destination then it must be a list import
		if (array_key_exists('DestinationObject', $this->props)) {
			if (array_key_exists('ID', $this->props['DestinationObject'])) {
				$this->props['DestinationObject'] = new \SoapVar($this->props['DestinationObject'], SOAP_ENC_OBJECT,
				                                                 'List', "http://exacttarget.com/wsdl/partnerAPI");
			}
			elseif (array_key_exists('CustomerKey', $this->props['DestinationObject'])) {
				$this->props['DestinationObject']->ObjectID = null;
				$this->props['DestinationObject'] = new \SoapVar($this->props['DestinationObject'], SOAP_ENC_OBJECT,
				                                                 'DataExtension', "http://exacttarget.com/wsdl/partnerAPI");
			}
		}
		dump(['post ET_Import' => $this->props]);
		$obj         = parent::post();
		$this->props = $originalProp;
		return $obj;
	}

	function start() {
		$originalProps = $this->props;
		Log::debug('Importdefinition: ET_Perform import ', [$this->props]);
		$response      = new ET_Perform($this->authStub, $this->obj, 'start', $this->props);
		if ($response->status) {
			$this->lastTaskID = $response->results[0]->Task->ID;
		}

		$this->props = $originalProps;
		return $response;
	}

	function status() {
		$this->filter        = ['Property' => 'TaskResultID', 'SimpleOperator' => 'equals', 'Value' => $this->lastTaskID];
		$response            = new ET_Get($this->authStub, 'ImportResultsSummary', [
			'ImportDefinitionCustomerKey',
			'TaskResultID',
			'ImportStatus',
			'StartDate',
			'EndDate',
			'DestinationID',
			'NumberSuccessful',
			'NumberDuplicated',
			'NumberErrors',
			'TotalRows',
			'ImportType'
		], $this->filter);
		$this->lastRequestID = $response->request_id;
		return $response;
	}

	function all() {
		$response            = new ET_Get($this->authStub, 'ImportResultsSummary', [
			'ImportDefinitionCustomerKey',
			'TaskResultID',
			'ImportStatus',
			'StartDate',
			'EndDate',
			'DestinationID',
			'NumberSuccessful',
			'NumberDuplicated',
			'NumberErrors',
			'TotalRows',
			'ImportType'
		], false);
		$this->lastRequestID = $response->request_id;
		return $response;
	}
}

class ET_ImportResultsSummary extends ET_GetSupport {
	function __construct() {
		$this->obj = 'ImportResultsSummary';
	}
}

class ET_FileTransferActivity extends ET_GetSupport {
	function __construct() {
		$this->obj = "FileTransferActivity";
	}

	function get() {
		throw new MethodNotAllowedException('File Transfers can only be performed, not retrieved.');
	}

	function transfer() {
		$originalProps = $this->props;
		$response      = new ET_Perform($this->authStub, $this->obj, 'start', $this->props);
		if ($response->status) {
			$this->lastTaskID = $response->results[0]->Task->ID;
		}
		$this->props = $originalProps;
		return $response;
	}
}


class ET_ProfileAttribute extends ET_BaseObject {
	function __construct() {
		$this->obj = "PropertyDefinition";
	}

	function post() {
		return new ET_Configure($this->authStub, $this->obj, "create", $this->props);
	}

	function get() {
		return new ET_Info($this->authStub, 'Subscriber', true);
	}

	function patch() {
		return new ET_Configure($this->authStub, $this->obj, "update", $this->props);
	}

	function delete() {
		return new ET_Configure($this->authStub, $this->obj, "delete", $this->props);
	}
}

class ET_Folder extends ET_CUDSupport {
	function __construct() {
		$this->obj = "DataFolder";
	}
}

class ET_List extends ET_CUDWithUpsertSupport {
	public $folderId;

	function __construct() {
		$this->obj = "List";
//		$this->folderProperty  = "Category";
//		$this->folderMediaType = "list";
	}
}

class ET_List_Subscriber extends ET_GetSupport {
	function __construct() {
		$this->obj = "ListSubscriber";
	}
}

class ET_TriggeredSend extends ET_CUDSupport {
	public $subscribers, $folderId;

	function __construct() {
		$this->obj             = "TriggeredSendDefinition";
		$this->folderProperty  = "CategoryID";
		$this->folderMediaType = "triggered_send";
	}

	/**
	 * Trigger the send.
	 * @param array $subscribers [EmailAddress,SubscriberKey,[dataextensionfield01, ..., dataextensionfieldNN]]
	 * $this->props = [CustomerKey = Triggered Send Definition - External key];
	 * @return \FuelSdkPhp\ET_Post
	 */
	public function Send($subscribers = null) {
		if ($subscribers) {
			$this->subscribers = $subscribers;
		}
		$tscall   = ["TriggeredSendDefinition" => $this->props, "Subscribers" => $this->subscribers];
		$response = new ET_Post($this->authStub, "TriggeredSend", $tscall);
		return $response;
	}
}

// Tracking Events
class ET_SentEvent extends ET_GetSupport {
	public $getSinceLastBatch;

	function __construct() {
		$this->obj               = "SentEvent";
		$this->getSinceLastBatch = true;
	}
}

class ET_OpenEvent extends ET_GetSupport {
	public $getSinceLastBatch;

	function __construct() {
		$this->obj               = "OpenEvent";
		$this->getSinceLastBatch = true;
	}
}

class ET_BounceEvent extends ET_GetSupport {
	public $getSinceLastBatch;

	function __construct() {
		$this->obj               = "BounceEvent";
		$this->getSinceLastBatch = true;
	}
}

class ET_UnsubEvent extends ET_GetSupport {
	public $getSinceLastBatch;

	function __construct() {
		$this->obj               = "UnsubEvent";
		$this->getSinceLastBatch = true;
	}
}

class ET_ClickEvent extends ET_GetSupport {
	public $getSinceLastBatch;

	function __construct() {
		$this->obj               = "ClickEvent";
		$this->getSinceLastBatch = true;
	}
}

class ET_Organization extends ET_CUDSupport {
	function __construct() {
		$this->obj = "Account";
	}
}

class ET_BusinessUnit extends ET_CUDSupport {
	function __construct() {
		$this->obj = "BusinessUnit";
	}
}

class ET_User extends ET_CUDSupport {
	function __construct() {
		$this->obj = "AccountUser";
	}
}

class ET_Role extends ET_CUDSupport {
	function __construct() {
		$this->obj = "Role";
	}
}

class ET_Send extends ET_CUDSupport {
	function __construct() {
		$this->obj = "Send";
	}
}

class ET_SenderProfile extends ET_CUDSupport {
	function __construct() {
		$this->obj = "SenderProfile";
	}
}

class ET_FilterDefinition extends ET_CUDSupport {
	function __construct() {
		$this->obj = "FilterDefinition";
	}
}

class ET_QueryDefinition extends ET_CUDSupport {
	function __construct() {
		$this->obj = "QueryDefinition";
	}
}

/**
 * Class ET_ExtractDefinition
 * Perform Data Extract Activities
 * @package FuelSdkPhp
 */
class ET_ExtractDefinition extends ET_GetSupport {
	function __construct() {
		$this->obj = "ExtractDefinition";
	}

	function send() {
		$originalProps = $this->props;
		$response      = new ET_Perform($this->authStub, $this->obj, 'start', $this->props);
		if ($response->status) {
			$this->lastTaskID = $response->results[0]->Task->ID;
		}
		$this->props = $originalProps;
		return $response;
	}
}

/**
 * Class ET_ExtractDescription
 * @package FuelSdkPhp
 */
class ET_ExtractDescription extends ET_CUDSupport {
	function __construct() {
		$this->obj = "ExtractDescription";
	}
}

/**
 * Perform a Data Extract Activity
 * Class ET_DataExtractActivity
 * @package FuelSdkPhp
 */
class ET_DataExtractActivity extends ET_CUDSupport {
	function __construct() {
		$this->obj = "DataExtractActivity";
	}

    function send() {
        $originalProps = $this->props;
        $response      = new ET_Perform($this->authStub, $this->obj, 'start', $this->props);
        if ($response->status) {
            $this->lastTaskID = $response->results[0]->Task->ID;
        }
        $this->props = $originalProps;
        return $response;
    }
}

class ET_ExtractResult extends ET_GetSupport {
	function __construct()
	{
		$this->obj = "AsyncActivityStatus";
	}
}

class ET_Template extends ET_CUDSupport {
	function __construct() {
		$this->obj = "Template";
	}
}

function restGet($url) {
	$ch      = curl_init();
	$headers = ["User-Agent: " . getSDKVersion()];
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	// Uses the URL passed in that is specific to the API used
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPGET, true);
	// Need to set ReturnTransfer to True in order to store the result in a variable
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Disable VerifyPeer for SSL
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	$outputJSON               = curl_exec($ch);
	$responseObject           = new \stdClass();
	$responseObject->body     = $outputJSON;
	$responseObject->httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return $responseObject;
}

// Function for calling a Fuel API using POST
/**
 * @param string $url The resource URL for the REST API
 * @param string $content A string of JSON which will be passed to the REST API
 *
 * @return string     The response payload from the REST service
 */
function restPost($url, $content) {
	$ch = curl_init();
	// Uses the URL passed in that is specific to the API used
	curl_setopt($ch, CURLOPT_URL, $url);
	// When posting to a Fuel API, content-type has to be explicitly set to application/json
	$headers = ["Content-Type: application/json", "User-Agent: " . getSDKVersion()];
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	// The content is the JSON payload that defines the request
	curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
	//Need to set ReturnTransfer to True in order to store the result in a variable
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Disable VerifyPeer for SSL
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	$outputJSON               = curl_exec($ch);
	$responseObject           = new \stdClass();
	$responseObject->body     = $outputJSON;
	$responseObject->httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return $responseObject;
}

// Function for calling a Fuel API using PATCH
/**
 * @param string $url The resource URL for the REST API
 * @param string $content A string of JSON which will be passed to the REST API
 *
 * @return string     The response payload from the REST service
 */
function restPatch($url, $content) {
	$ch = curl_init();
	// Uses the URL passed in that is specific to the API used
	curl_setopt($ch, CURLOPT_URL, $url);
	// When posting to a Fuel API, content-type has to be explicitly set to application/json
	$headers = ["Content-Type: application/json", "User-Agent: " . getSDKVersion()];
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	// The content is the JSON payload that defines the request
	curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
	//Need to set ReturnTransfer to True in order to store the result in a variable
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	//Need to set the request to be a PATCH
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
	// Disable VerifyPeer for SSL
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	$outputJSON               = curl_exec($ch);
	$responseObject           = new \stdClass();
	$responseObject->body     = $outputJSON;
	$responseObject->httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return $responseObject;
}

// Function for calling a Fuel API using PATCH
/**
 * @param string $url The resource URL for the REST API
 * @param string $content A string of JSON which will be passed to the REST API
 *
 * @return string     The response payload from the REST service
 */
function restPut($url, $content) {
	$ch = curl_init();
	// Uses the URL passed in that is specific to the API used
	curl_setopt($ch, CURLOPT_URL, $url);
	// When posting to a Fuel API, content-type has to be explicitly set to application/json
	$headers = ["Content-Type: application/json", "User-Agent: " . getSDKVersion()];
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	// The content is the JSON payload that defines the request
	curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
	//Need to set ReturnTransfer to True in order to store the result in a variable
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	//Need to set the request to be a PATCH
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
	// Disable VerifyPeer for SSL
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	$outputJSON               = curl_exec($ch);
	$responseObject           = new \stdClass();
	$responseObject->body     = $outputJSON;
	$responseObject->httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return $responseObject;
}

function restDelete($url) {
	$ch      = curl_init();
	$headers = ["User-Agent: " . getSDKVersion()];
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	// Uses the URL passed in that is specific to the API used
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPGET, true);
	// Need to set ReturnTransfer to True in order to store the result in a variable
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Disable VerifyPeer for SSL
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Set CustomRequest up for Delete
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
	$http_status              = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$outputJSON               = curl_exec($ch);
	$responseObject           = new \stdClass();
	$responseObject->body     = $outputJSON;
	$responseObject->httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return $responseObject;
}

function isAssoc($array) {
	if (!is_array($array)) {
		return false;
	}
	return ($array !== array_values($array));
}

function getSDKVersion() {
	return "FuelSDK-PHP-v0.9";
}

?>
