<?php
/**
 * Created by PhpStorm.
 * User: kene
 * Date: 2016-11-11
 * Time: 3:12 PM
 */

namespace ariad\exacttargetLaravel;

use FuelSdkPhp\ET_Client;

/**
 * extend ExactTargetLaravelApi with a version that allows passing in ClientID and Secret
 * Class Et
 * @package ariad\exacttargetLaravel
 */
class Et extends ExactTargetLaravelApi {

	public $config;

	public function __construct(Array $config) {
		$this->config = $config;
		parent::__construct();
	}

	public function getConfig() {
		$this->fuel = new ET_Client(false, false, $this->config);
		return $this->config;
	}

}