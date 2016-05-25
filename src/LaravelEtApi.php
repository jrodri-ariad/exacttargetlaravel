<?php namespace ariad\exacttargetlaravel;

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
 * Class EtApi
 * @package App
 */
class LaravelEtApi implements EtInterface {

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
    function __construct(Client $client, ET_DataExtension_Row $fuelDe, ET_DataExtension_Column $fuelDeColumn, ET_DataExtension $fuelDext, $config = null) {
        $this->getTokenUri  = 'https://auth.exacttargetapis.com/v1/requestToken';
        $this->client       = $client;
        $this->fuelDeColumn = $fuelDeColumn;
        $this->fuelDe       = $fuelDe;
        $this->fuelDext     = $fuelDext;

        if ($config) {
            $config['xmlloc']   = __DIR__ . '/../wsdl/ExactTargetWSDL.xml';
            $this->config       = $config;
            $this->clientId     = $config['clientid'];
            $this->clientSecret = $this->config['clientsecret'];


        }
        else {
            $this->config       = $this->getConfig();
            $this->clientId     = $this->config['clientid'];
            $this->clientSecret = $this->config['clientsecret'];
        }

        $this->fuel        = new ET_Client(false, false, $config);
        $this->accessToken = $this->getToken($this->clientId, $this->clientSecret, $this->getTokenUri);

    }

    public function getConfig() {
        if (file_exists(__DIR__ . '/../config.php')) {
            $config = include __DIR__ . '/../config.php';

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
    public function upsertRowset($values, $deKey) {

        $upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataevents/key:' . $deKey . '/rowset';

        $serialized = [];

        foreach ($values as $record) {
            foreach ($record as $k => $v) {
                $serialized[] =
                     [
                          "keys" => $v['keys'],
                          "values" => $v['values']
                     ];
            }
        }
        $serialized = json_encode($serialized);

        $request['body'] = $serialized;

        $request['headers'] = [
             'Content-Type' => 'application/json',
             'Accept' => 'application/json',
             'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
        ];

        try {
            //post upsert
            $response     = $this->client->post($upsertUri, $request);
            $responseBody = json_decode($response->getBody());

        }
        catch (BadResponseException $exception) {
            //spit out exception if curl fails or server is angry
            $exc = $exception->getResponse()->getBody(true);
            //echo $exc. "\n";
            return false;
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
            return $getRes->message;
        }

        return print 'Message: ' . $getRes->message . "\n";
    }


    /**
     * @param $deName
     *  Required -- Name of the Data Extension to query
     *
     * @return array
     */
    public function getDeColumns($deName) {

        //Get all Data Extensions Columns filter by specific DE

        $this->fuelDeColumn->authStub = $this->fuel;

        $this->fuelDeColumn->filter = array('Property' => 'CustomerKey', 'SimpleOperator' => 'equals', 'Value' => $deName);

        $getResult = $this->fuelDeColumn->get();

        if ($getResult->status == true) {
            return $getResult->results;
        }

        return print 'Message: ' . $getResult->message . "\n";
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

        //if the function is calle with these values -- filter by them
        if ($primaryKey !== '' && $keyName !== '') {
            $this->fuelDe->filter = array('Property' => $keyName, 'SimpleOperator' => 'equals', 'Value' => $primaryKey);
        }

        //get rows from the columns
        $getRes = $this->fuelDe->get();

        if ($getRes->status == true) {
            return $getRes;
        }

        return print 'Message: ' . $getRes->message . "\n";
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
    public function asyncUpsertRowset($keys, $values, $deKey) {
        $upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataeventsasync/key:' . $deKey . '/rowset';

        //api implementation style
        $request['body'] = json_encode([[
             "keys" => $keys,
             "values" => $values
        ]]);

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
                     echo $res->getStatusCode() . "\n";
                 },
                 function (RequestException $e) {
                     echo $e->getMessage() . "\n";
                     echo $e->getRequest()->getMethod();
                 }
            );
        }
        catch (BadResponseException $exception) {
            //spit out exception if curl fails or server is angry
            $exc = $exception->getResponse()->getBody(true);
            echo $exc;

        }

        return compact('promise');
    }


    /**
     * PUT
     *
     * Upserts a data extension row by key.
     *
     * /dataevents/key:{key}/rows/{primaryKeys}
     */
    public function upsertRow($pKey, $pVal, $values, $deKey) {
        $upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataevents/key:' . $deKey . '/rows/' . $pKey . ':' . $pVal;

        $request['headers'] = [
             'Content-Type' => 'application/json',
             'Accept' => 'application/json',
             'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
        ];

        //api implementation style
        $request['body'] = json_encode([
                                                      "values" => $values
                                                 ]);

        try {
            //post upsert
            $response     = $this->client->put($upsertUri, $request);
            $responseBody = json_decode($response->getBody());

        }
        catch (BadResponseException $exception) {
            //spit out exception if curl fails or server is angry
            $exc = $exception->getResponse()->getBody(true);
            echo "Oh No! Something went wrong! " . $exc;
            return false;
        }
        return compact('responseBody');
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
    public function asyncUpsertRow($pKey, $pVal, $values, $deKey) {
        $upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataeventsasync/key:' . $deKey . '/rows/' . $pKey . ':' . $pVal;

        $request['headers'] = [
             'Content-Type' => 'application/json',
             'Accept' => 'application/json',
             'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
        ];

        //api implementation style
        $request['body'] = json_encode([
                                                      "values" => $values
                                                 ]);

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
            return compact('getRes');
        }
        else {
            Log::error("Error creating Row", [$getRes]);
            return false;
        }


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

        $response     = $this->client->post('https://www.exacttargetapis.com/address/v1/validateEmail', $request);
        $responseBody = json_decode($response->getBody());

        return compact('responseBody');
    }


    /**
     * Create a Data extension by passing an array of DE Name keys => Column props values.
     *
     * @param $deStructures
     * @return array (response)
     */
    public function createDe($deStructures, $BusinessUnit = false) {
        $this->fuelDext->authStub = $this->fuel;

        foreach ($deStructures as $k => $name) {

            $this->fuelDext->props = [
                 "Name" => $k,
                 "CustomerKey" => $k
            ];

            if ($BusinessUnit) {
                //define Business Unit ID (mid)
                $this->fuelDext->authStub->BusinessUnit = (object)['ID' => $BusinessUnit, 'ClientID' => $BusinessUnit];
                $this->fuelDext->props['Client']        = array('ID' => $BusinessUnit, 'IDSpecified' => true);
            }

            $this->fuelDext->props['IsSendable'] = true;
//            $this->fuelDext->props['SendableDataExtensionField'] = 'EMAIL';
            //$this->fuelDext->props['IsSendableSpecified'] = true;
            $this->fuelDext->props['SendableDataExtensionField'] = (object)['Name' => 'email', 'Value' => ''];
            $this->fuelDext->props['SendableSubscriberField']    = (object)['Name' => 'Subscriber Key', 'Value' => 'Subscriber Key'];


            $this->fuelDext->columns = [];

            foreach ($name as $key => $val) {
                $this->fuelDext->columns[] = $val;
            }
            try {
                $getRes = $this->fuelDext->post();

            }
            catch (Exception $e) {
                Log::error("Error creating Data Extension", [$getRes]);
                return false;
            }
        }

        return compact('getRes');
    }

    public function deleteDe($deName) {
        $this->fuelDext->authStub = $this->fuel;

        $this->fuelDext->props = [
             "CustomerKey" => $deName
        ];

        $this->fuelDext->columns = [];

        try {
            $getRes = $this->fuelDext->delete();
        }
        catch (Exception $e) {
            Log::error("Error deleting Data Extension", [$e]);
            return false;
        }


        return compact('getRes');
    }


    /**
     * Gets all the existing Data Extensions
     *
     */
    public function getDes($BusinessUnit = false) {
        $this->fuelDext->authStub = $this->fuel;

        //dd($this->fuelDext->authStub);
        $this->fuelDext->props = array(
             'Client.ID',
             'CustomerKey',
             'Name'
        );

        if ($BusinessUnit) {
            $this->fuelDext->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
            //$this->fuelDext->filter = array('Property' => 'Client.ID', 'SimpleOperator' => 'equals','Value' => $BusinessUnit);
        }
        try {
            $getRes = $this->fuelDext->get();

        }
        catch (Exception $e) {
            Log::error("Error getting Data Extension", [$e]);
            return false;
        }

        return compact('getRes');

    }


    /**
     * Gets Data Extension
     *
     */
    public function getDe($name, $BusinessUnit = false) {
        $this->fuelDext->authStub = $this->fuel;

        $this->fuelDext->props = array('Client.ID', 'CustomerKey', 'Name', 'CategoryID');

        $this->fuelDext->filter = array('Property' => 'CustomerKey', 'SimpleOperator' => 'equals', 'Value' => $name);

        if ($BusinessUnit) {
            //define Business Unit ID (mid)
            $this->fuelDext->authStub->BusinessUnit = (object)['ID' => $BusinessUnit];
//            $this->fuelDext->filter = array(
//                'LeftOperand' => array('Property' => 'CustomerKey', 'SimpleOperator' => 'equals','Value' => $name),
//                'LogicalOperator' => 'AND',
//                'RightOperand' => array('Property' => 'Client.ID', 'SimpleOperator' => 'equals','Value' => $BusinessUnit)
//            );
        }

        try {
            $getRes = $this->fuelDext->get();

        }
        catch (Exception $e) {
            Log::error("Error getting Data Extension", [$e]);
            return false;
        }

        return compact('getRes');

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

        $clicks_opens = $this->GetTotals($sendIds);

        if ($getRes->status == true) {
            $getRes->Totals = $clicks_opens;
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
     */
    public function GetTotals($sendIDs) {

        if (count($sendIDs) > 1) {
            $operator = 'IN';
            $ids      = $sendIDs;
        }
        else {
            $operator = 'equals';
            $ids      = $sendIDs[0];
        }
        $props   = array("SendID", "EventDate", "SubscriberKey", "EventType"); // this is accumulative! need to cache the count locally! https://code.exacttarget.com/getting-started/building-an-app/retrieving-tracking-event-data.html
        $filters = array('Property' => 'SendID', 'SimpleOperator' => $operator, 'Value' => $ids);

        $et_openevents           = new ET_OpenEvent();
        $et_clickevents           = new ET_ClickEvent();

        $total_opens  = $this->get_total_events($et_openevents, $props, $filters);
        $total_clicks = $this->get_total_events($et_clickevents, $props, $filters);
        Log::info('total_opens: ' . $total_opens . 'total_clicks: ' . $total_clicks);
        return ['clicks' => $total_clicks, 'opens' => $total_opens];
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
        $counts = $this->reduce_total_events($response->results);

        while ($response->moreResults == true) {// Keep querying API if more results exist
            $response          = new ET_Continue($this->fuel, $response->request_id);
            $counts = $this->reduce_total_events($response->results);
        }
// Now we have totals of the event
        return $counts;
    }

    private function reduce_total_events($results) {
        $counts = [];
        foreach ($results as $result ) {
            if (array_key_exists($result->SendID, $counts )) {
                $counts[$result->SendID] ++;
            }
            else {
                $counts[$result->SendID] = 1;
            }
        }
    }



    public function getFolders() {
        $objectType = "DataFolder";
        $sendProps  = array(
             'ID',
             'Name',
        );

        $sendFilter  = null;
        $getResponse = new ET_Get($this->fuel, $objectType, $sendProps, $sendFilter);

        $view_data = Array();

        $view_data = $getResponse->results;

        return $view_data;
    }


    public function createEmail($name, $subject, $html) {
        $email           = new \ET_Email();
        $email->authStub = $this->fuel;
        $email->props    = array(
             'CustomerKey' => $name,
             'Name' => $name,
             'Subject' => $subject,
             'HTMLBody' => $html
        );

        $getRes = $email->post();

        if ($getRes->status == true) {
            return $getRes;
        }
        else {
            Log::error('Error creating ET email(createEmail). Message: ', [$getRes]);
            return false;
        }

    }

    public function retrieveEmails($name = null) {
        $email           = new \ET_Email();
        $email->authStub = $this->fuel;

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
    public function sendEmailToDataExtension($email, $publicationListID, $DEname = false, $emailClassification = "Default Commercial") {
        $SendClassificationCustomerKey = "Default Commercial";
        $EmailIDForSendDefinition      = $email;
        $sd                            = new \ET_Email_SendDefinition();
        $sd->authStub                  = $this->fuel;
        $sd->props                     = array(
             'Name' => uniqid(),
             'CustomerKey' => uniqid(),
             'Description' => "Created with Mason",
             'SendClassification' => array("CustomerKey" => $SendClassificationCustomerKey),
             'Email' => array("ID" => $EmailIDForSendDefinition)
        );

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
     * @return bool|\ET_Patch
     * @throws \Exception
     */
    public function sendTriggered($email, $emailid) {
        $sendClassification = "Default Commercial";
        $name               = uniqid();
        $ts                 = new \ET_TriggeredSend();
        $ts->authStub       = $this->fuel;

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
             'EmailSubject' => 'Testing Mason Triggered Send',
             'TriggeredSendStatus' => 'Active',
             'RefreshContent' => 'true',
             'SuppressTracking' => 'true',
             'Priority' => 'High'
        );

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
