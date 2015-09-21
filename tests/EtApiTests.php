<?php
 
use ariad\exacttargetlaravel\LaravelEtApi;
 
class EtApiTest extends PHPUnit_Framework_TestCase {
 
  public function testEtApi()
  {
    $etApi = new LaravelEtApi();
    $this->assertTrue($$etApi->getToken);
  }
 
}