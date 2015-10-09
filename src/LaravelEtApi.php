<?php namespace ariad\exacttargetlaravel;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use ET_Client;
use ET_GET;
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
     * Construction Work
     *
     * @param Client $client
     * @param ET_Client $fuel
     * @param ET_DataExtension_Row $fuelDe
     * @param ET_DataExtension_Column $fuelDeColumn
     * @param ET_Get $fuelGet
     */
    function __construct(Client $client, ET_Client $fuel, ET_DataExtension_Row $fuelDe, ET_DataExtension_Column $fuelDeColumn, ET_DataExtension $fuelDext)
    {

        $this->getTokenUri = 'https://auth.exacttargetapis.com/v1/requestToken';
        $this->client = $client;
        $this->fuelDeColumn = $fuelDeColumn;
        $this->fuel = $fuel;
        $this->fuelDe = $fuelDe;
        $this->fuelDext = $fuelDext;
        $this->config = $this->getConfig();
        $this->clientId = $this->config['clientid'];
        $this->clientSecret = $this->config['clientsecret'];
        $this->accessToken = $this->getToken($this->clientId, $this->clientSecret, $this->getTokenUri);

    }

    public function getConfig()
    {
        if (file_exists(__DIR__ .'/../config.php'))
        {
            $config = include __DIR__ .'/../config.php';

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
    public function getToken($clientId, $clientSecret, $getTokenUri)
    {
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
            'Accept'       => 'application/json'
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
    public function upsertRowset($values, $deKey)
    {

        $upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataevents/key:'.$deKey.'/rowset';

        $serialized = [];

        foreach ($values as $k => $v)
        {
            $serialized[] =
                [
                    "keys" => $v['keys'],
                    "values" => $v['values']
                ];
        }
        $serialized = json_encode($serialized);

        $request['body'] = $serialized;

        $request['headers'] = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
        ];

        try {
            //post upsert
            $response = $this->client->post($upsertUri, $request);
            $responseBody = json_decode($response->getBody());

        } catch (BadResponseException $exception) {
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
    public function deleteRow($deName, $props)
    {
        //new up & auth up ET Fuel
        $this->fuelDe->authStub = $this->fuel;

        $this->fuelDe->props = $props;

        $this->fuelDe->CustomerKey = $deName;

        $getRes = $this->fuelDe->delete();

        if ($getRes->status == true)
        {
            return $getRes->message;
        }

        return print 'Message: '.$getRes->message."\n";
    }


    /**
     * @param $deName
     *  Required -- Name of the Data Extension to query
     *
     * @return array
     */
    public function getDeColumns($deName)
    {

        //Get all Data Extensions Columns filter by specific DE

        $this->fuelDeColumn->authStub = $this->fuel;

        $this->fuelDeColumn->filter = array('Property' => 'CustomerKey','SimpleOperator' => 'equals','Value' => $deName);

        $getResult = $this->fuelDeColumn->get();

        if ($getResult->status == true)
        {
            return $getResult->results;
        }

        return print 'Message: '.$getResult->message."\n";
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
    public function getRows($deName, $keyName='', $primaryKey='')
    {
        //get column names from DE
        $deColumns = $this->getDeColumns($deName);

        //new up & auth up ET Fuel
        $this->fuelDe->authStub = $this->fuel;

        $this->fuelDe->Name = $deName;

        //build array of Column names from DE
        foreach ($deColumns as $k => $v)
        {
            $this->fuelDe->props[] = $v->Name;
        }

        //if the function is calle with these values -- filter by them
        if ($primaryKey !== '' && $keyName !== '')
        {
            $this->fuelDe->filter = array('Property' => $keyName,'SimpleOperator' => 'equals','Value' => $primaryKey);
        }

        //get rows from the columns
        $getRes = $this->fuelDe->get();

        if ($getRes->status == true)
        {
            return $getRes;
        }

        return print 'Message: '.$getRes->message."\n";
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
    public function asyncUpsertRowset($keys, $values, $deKey)
    {
        $upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataeventsasync/key:'.$deKey.'/rowset';

        //api implementation style
        $request['body'] = json_encode([[
            "keys" => $keys,
            "values" => $values
        ]]);

        $request['headers'] = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
        ];

        try {
            //post upsert
            $promise = $this->client->postAsync($upsertUri, $request);
            $promise->then(
            //chain logic to the response (can fire from other classes or set booleans)
                function(ResponseInterface $res)
                {
                    echo $res->getStatusCode() . "\n";
                },
                function(RequestException $e)
                {
                    echo $e->getMessage() . "\n";
                    echo $e->getRequest()->getMethod();
                }
            );
        }
        catch (BadResponseException $exception)
        {
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
    public function upsertRow($pKey, $pVal, $values, $deKey)
    {
        $upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataevents/key:'.$deKey.'/rows/'.$pKey.':'.$pVal;

        $request['headers'] = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
        ];

        //api implementation style
        $request['body'] = json_encode([
            "values" => $values
        ]);

        try {
            //post upsert
            $response = $this->client->put($upsertUri, $request);
            $responseBody = json_decode($response->getBody());

        }
        catch (BadResponseException $exception)
        {
            //spit out exception if curl fails or server is angry
            $exc = $exception->getResponse()->getBody(true);
            echo "Oh No! Something went wrong! ".$exc;
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
    public function asyncUpsertRow($pKey, $pVal, $values, $deKey)
    {
        $upsertUri = 'https://www.exacttargetapis.com/hub/v1/dataeventsasync/key:'.$deKey.'/rows/'.$pKey.':'.$pVal;

        $request['headers'] = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
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
                function(ResponseInterface $res)
                {
                    echo $res->getStatusCode() . "\n";
                },
                function(RequestException $e)
                {
                    echo $e->getMessage() . "\n";
                    echo $e->getRequest()->getMethod();
                }
            );
        }
        catch (BadResponseException $exception)
        {
            //spit out exception if curl fails or server is angry
            $exc = $exception->getResponse()->getBody(true);
            echo "Oh No! Something went wrong! ".$exc;
        }

        return compact('promise');
    }

    /**
     * Create a Data extension by passing an array of DE Name keys => Column props values.
     *
     * @param $deStructures
     * @return array (response)
     */
    public function createRow($deName, $props)
    {

        //new up & auth up ET Fuel
        $this->fuelDe->authStub = $this->fuel;

        $this->fuelDe->Name = $deName;

        $this->fuelDe->props = $props;

        $getRes = $this->fuelDe->post();

        if ($getRes->status == true)
        {
            return compact('getRes');
        }

        return print 'Message: '.$getRes->message."\n";
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
    public function validateEmail($email)
    {
        $request['headers'] = [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken['response']->accessToken
        ];

        $request['body'] = json_encode([
            "email" => $email,
            "validators" => ["SyntaxValidator", "MXValidator", "ListDetectiveValidator"]
        ]);

        $response = $this->client->post('https://www.exacttargetapis.com/address/v1/validateEmail', $request);
        $responseBody = json_decode($response->getBody());

        return compact('responseBody');
    }


    /**
     * Create a Data extension by passing an array of DE Name keys => Column props values.
     *
     * @param $deStructures
     * @return array (response)
     */
    public function createDe($deStructures, $BusinessUnit = false)
    {
        $this->fuelDext->authStub = $this->fuel;

        foreach ($deStructures as $k => $name)
        {

            $this->fuelDext->props = [
                "Name" => $k,
                "CustomerKey" => $k
            ];

            if ($BusinessUnit){
                //define Business Unit ID (mid)
                $this->fuelDext->authStub->BusinessUnit = (object)['ID'=>$BusinessUnit, 'ClientID'=>$BusinessUnit];
                $this->fuelDext->props['Client'] = array('ID'=>$BusinessUnit, 'IDSpecified'=> true);
            }

            $this->fuelDext->props['IsSendable'] = true;
//            $this->fuelDext->props['SendableDataExtensionField'] = 'EMAIL';
            //$this->fuelDext->props['IsSendableSpecified'] = true;
              $this->fuelDext->props['SendableDataExtensionField'] = (object) ['Name'=>'email', 'Value'=>''];
              $this->fuelDext->props['SendableSubscriberField'] = (object) ['Name'=>'Subscriber Key', 'Value'=>'Subscriber Key'];


            $this->fuelDext->columns = [];

            foreach ($name as $key => $val)
            {
                $this->fuelDext->columns[] = $val;
            }
            try
            {
                $getRes = $this->fuelDext->post();

                print 'The Following DE was created: '. $k. "\n";
            }
            catch (Exception $e)
            {
                echo "Oh No! Something went wrong! ".$e;

                print 'Message: '.$getRes->message."\n";
            }
        }

        return compact('getRes');
    }

    public function deleteDe($deName){
        $this->fuelDext->authStub = $this->fuel;

        $this->fuelDext->props = [
            "CustomerKey" => $deName
        ];

        $this->fuelDext->columns = [];

        try
        {
            $getRes = $this->fuelDext->delete();

            print 'The Following DE was deleted: '. $deName. "\n";
        }
        catch (Exception $e)
        {
            echo "Oh No! Something went wrong! ".$deName;
            print 'Message: '.$getRes->message."\n";
        }


        return compact('getRes');
    }


    /**
     * Gets all the existing Data Extensions
     *
     */
    public function getDes($BusinessUnit = false){
        $this->fuelDext->authStub = $this->fuel;

        //dd($this->fuelDext->authStub);
        $this->fuelDext->props = array(
            'Client.ID',
            'CustomerKey',
            'Name'
        );

        if ($BusinessUnit) {
            $this->fuelDext->authStub->BusinessUnit = (object)['ID'=>$BusinessUnit];
            //$this->fuelDext->filter = array('Property' => 'Client.ID', 'SimpleOperator' => 'equals','Value' => $BusinessUnit);
        }
        try
        {
            $getRes = $this->fuelDext->get();

        }
        catch (Exception $e)
        {
            echo "Oh No! Something went wrong! ".$e;
            print 'Message: '.$getRes->message."\n";
        }

        return compact('getRes');

    }


    /**
     * Gets Data Extension
     *
     */
    public function getDe($name, $BusinessUnit = false){
        $this->fuelDext->authStub = $this->fuel;

        $this->fuelDext->props = array('Client.ID','CustomerKey','Name', 'CategoryID');

        $this->fuelDext->filter = array('Property' => 'CustomerKey', 'SimpleOperator' => 'equals','Value' => $name);

        if ($BusinessUnit){
            //define Business Unit ID (mid)
            $this->fuelDext->authStub->BusinessUnit = (object)['ID'=>$BusinessUnit];
//            $this->fuelDext->filter = array(
//                'LeftOperand' => array('Property' => 'CustomerKey', 'SimpleOperator' => 'equals','Value' => $name),
//                'LogicalOperator' => 'AND',
//                'RightOperand' => array('Property' => 'Client.ID', 'SimpleOperator' => 'equals','Value' => $BusinessUnit)
//            );
        }

        try
        {
            $getRes = $this->fuelDext->get();

        }
        catch (Exception $e)
        {
            echo "Oh No! Something went wrong! ".$e;

            print 'Message: '.$getRes->message."\n";
        }

        return compact('getRes');

    }

    public function getSends($sendIds, $startDate= null, $endDate=null){
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
        }else{
            if (count($sendIds)>0) {
                if (count($sendIds) > 1) {
                    $sendFilter = array('Property' => 'ID', 'SimpleOperator' => 'IN', 'Value' => $sendIds);
                } else {
                    $sendFilter = array('Property' => 'ID', 'SimpleOperator' => 'equals', 'Value' => $sendIds[0]);
                }
            }
        }

        $getRes = new ET_Get($this->fuel, $objectType, $sendProps, $sendFilter);

        if ($getRes->status == true)
        {
            return $getRes;
        }else{
            Log::error('Error creating ET email(createEmail). Message: ' . $getRes->message );
            return false;
        }
    }


    public function getFolders(){
        $objectType = "DataFolder";
        $sendProps = array(
            'ID',
            'Name',
            );

        $sendFilter = null;
        $getResponse = new ET_Get($this->fuel, $objectType, $sendProps, $sendFilter);

        $view_data = Array();

        $view_data = $getResponse->results;

        return $view_data;
    }


    public function createEmail($name, $subject, $html){
        $email = new \ET_Email();
        $email->authStub = $this->fuel;
        $email->props = array(
            'CustomerKey'=> $name,
            'Name'=>$name,
            'Subject' => $subject,
            'HTMLBody' => $html
        );

        $getRes = $email->post();

        if ($getRes->status == true)
        {
            return $getRes;
        }else{
            Log::error('Error creating ET email(createEmail). Message: ' . $getRes->message );
            return false;
        }

    }

    public function retrieveEmails(){
        $email = new \ET_Email();
        $email->authStub = $this->fuel;
        $getRes = $email->get();
        if ($getRes->status == true)
        {
            return $getRes;
        }else{
            Log::error('Error retrieving ET email(retrieveEmails). Message: ' . $getRes->message );
            return false;
        }

    }

    public function deleteEmails($id){
        $email = new \ET_Email();
        $email->authStub = $this->fuel;
        $email->props = array(
            'ID'=> $id
        );
        $getRes = $email->delete();

        if ($getRes->status == true)
        {
            return $getRes;
        }else{
            Log::error('Error retrieving ET email(retrieveEmails). Message: ' . $getRes->message );
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
    public function sendEmailToDataExtension($email, $DEname = false, $emailClassification = "Default Commercial"){
//        $SendClassificationCustomerKey = "Default Commercial";
//        $EmailIDForSendDefinition = $email;
//        $sd = new \ET_Email_SendDefinition();
//        $sd->authStub = $this->fuel;
//        $sd->props = array(
//            'Name'=>$name,
//            'CustomerKey'=>$name,
//            'Description'=>"Created with Mason",
//            'SendClassification'=>array("CustomerKey"=>$SendClassificationCustomerKey),
//            'Email'=>array("ID"=>$EmailIDForSendDefinition)
//        );
//
//        if ($DEname){
//            $sd->props["SendDefinitionList"] = array("CustomerKey" => $DEname, "DataSourceTypeID" => "CustomObject");
//        }
//
//        $getRes = $sd->post();

        $getRes = $this->fuel->SendEmailToDataExtension($email, $DEname, $emailClassification);
        //$res_send = $sd->send();

        if ($getRes->status == 'true')
        {
            return $getRes;
        }else{
            Log::error('Error creating ET email(createSendDefinition). Message: ', [$getRes] );
            return false;
        }
    }



    public function deleteSendDefinition($name){
        $sd = new \ET_Email_SendDefinition();
        $sd->authStub = $this->fuel;

        $sd->props = array(
            'CustomerKey'=>$name
        );

        $getRes = $sd->delete();

        if ($getRes->status == true)
        {
            return $getRes;
        }else{
            Log::error('Error deleting SendDefinition(deleteSendDefinition). Message: ', [$getRes] );
            return false;
        }

    }

    public function getSendDefinitions(){
        $sd = new \ET_Email_SendDefinition();
        $sd->authStub = $this->fuel;
        $sd->props = array(
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

        if ($getRes->status == true)
        {
            return $getRes;
        }else{
            Log::error('Error retrieving (getSendDefinition). Message: ' . $getRes->message );
            return false;
        }
    }


    public function getSendClassifications(){
        $objectType = "SendClassification";
        $sendProps = array(
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
        $getRes = new ET_Get($this->fuel, $objectType, $sendProps, $sendFilter);

        if ($getRes->status == true)
        {
            return $getRes;
        }else{
            Log::error('Error geting SendClassification ET (getSendClassification). Message: ' . $getRes->message );
            return false;
        }
    }


}