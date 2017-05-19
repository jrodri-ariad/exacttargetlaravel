<?php
/**
 * Created by PhpStorm.
 * User: kene
 * Date: 2017-03-24
 * Time: 11:20 AM
 */

namespace ariad\exacttargetLaravel;

use App\Models\AriadMediaMap;
use FuelSdkPhp\ET_Client;
use FuelSdkPhp\ET_Post;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class Portfolio extends ExactTargetLaravelApi {
	private $et_config;
	/**
	 * @var FTP Connection credentials
	 */
	private $ftp_host;
	private $ftp_username;
	private $ftp_password;
	private $ftp_connection;
	private $errors  = [];
	private $ftp_image_name;
	private $objType = 'Portfolio';

	/**
	 * Pass connection criteria to constructor
	 * @param $host
	 * @param $user
	 * @param $pass
	 */
	public function __construct($et_config, $host = false, $user = false, $pass = false) {
		$this->et_config = $et_config;
		parent::__construct();
		$this->ftp_host     = $host;
		$this->ftp_username = $user;
		$this->ftp_password = $pass;
		// Log::debug([$this->ftp_host, $this->ftp_username, $this->ftp_password]);
	}

	/**
	 * @return mixed Override the Parent ExactTargetLaravelAPI method to import credentials
	 */
	public function getConfig() {
		$this->fuel = new ET_Client(false, false, $this->et_config);
		return $this->et_config;
	}

	/**
	 * Copy a file from the local system to the SFMC Enhanced FTP
	 * @param string $filename The name of the file - local and remote
	 * @param string $localFilePath The path (directory) where the file is currently located
	 * @return bool true on success false on failure
	 */
	public function upload_to_ftp($filename, $localFilePath) {
		Config::set('remote.connections.sfmc.host', $this->ftp_host . ':22');
		Config::set('remote.connections.sfmc.username', $this->ftp_username);
		Config::set('remote.connections.sfmc.password', $this->ftp_password);
		Log::debug('putting ', [$localFilePath . $filename, $filename]);
		$ssh = \SSH::into('sfmc')->put($localFilePath . $filename, '/Import/' . $filename);
		$this->ftp_image_name = $filename;
		return true;
	}

	/**
	 * This requrest SFMC download the image pointed to by $props['URN']
	 * @param Array $props as outlined by SFMC Portfolio Object - Create a new Portfolio Object from Website
	 * @return bool|\FuelSdkPhp\ET_Post
	 */
	public function import_as_portfolio($props, $src) {
		if (array_key_exists('URN', $props)) {
			$props['Source'] = (object)['URN' => $props['URN']]; // 'http://email.exacttarget.com/images/' . $this->ftp_image_name];
			unset($props['URN']);
		}
		else {
			Log::critical('missing URN - the location where the image is being hosted.');
			return false;
		}
		try {
			ini_set("soap.wsdl_cache_enabled", "0");
			$response = new ET_Post($this->fuel, $this->objType, $props);
			if ($response->Status == true && count($response->results) && $response->results[0]->StatusCode == "OK") {
				$acm                 = new AriadMediaMap();
				$acm->src_client_id  = $src['business_unit'];
				$acm->customer_key   = $response->results[0]->Object->CustomerKey;
				$acm->src_id         = 0;
				$acm->src_object_id  = $src['object_id'];
				$acm->dest_client_id = $response->results[0]->Object->Client->ID;
				$acm->dest_id        = $response->results[0]->NewID;
				$acm->dest_object_id = $response->results[0]->NewObjectID;
				$acm->save();
			}
		}
		catch (\Exception $exception) {
			Log::critical('Failed Importing Portfolio image to Portfolio', [$exception]);
			return false;
		}
		Log::debug('Image imported: ', [$response]);
		return true;
	}
}
