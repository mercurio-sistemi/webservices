<?php

namespace GoetasWebservices\SoapServices\Tests;

use Gen\ExchangeConversionType;
use GoetasWebservices\SoapServices\Message\DiactorosFactory;
use GoetasWebservices\SoapServices\Server;
use GoetasWebservices\SoapServices\ServerFactory;
use GoetasWebservices\SoapServices\Tests\SimpleWsdl\GreetingType;
use GoetasWebservices\SoapServices\Tests\SimpleWsdl\InfoType;
use GoetasWebservices\SoapServices\Tests\Utils\Generator;
use Zend\Diactoros\ServerRequest;

class MainTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Server
     */
    protected $server;

    public function setUp()
    {
        $generator = new Generator();
        $namespaces = [
            'http://www.example.org/simple/' => 'GoetasWebservices\SoapServices\Tests\SimpleWsdl'
        ];
        $serializer = $generator->generate([
            __DIR__ . '/res/simple.wsdl'
        ], $namespaces);

        $factory = new ServerFactory($namespaces, $serializer);
        $this->server = $factory->getServer(__DIR__ . '/res/simple.wsdl');
    }

    public function testSayHello()
    {
        $r = trim('
        <soapenv:Envelope
            xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:sim="http://www.example.org/simple/">
           <soapenv:Header/>
           <soapenv:Body>
              <sim:sayHello>
                 <sim:in>Marc</sim:in>
              </sim:sayHello>
           </soapenv:Body>
        </soapenv:Envelope>');

        $h = function ($in) {
            return "hello $in";
        };

        $request = new ServerRequest([], [], null, 'POST', DiactorosFactory::toStream($r),
            ['Soap-Action' => 'http://www.example.org/simple/sayHello']
        );
        $response = $this->server->handle($request, $h);
        $this->assertXmlStringEqualsXmlString((string)$response->getBody(), '
        <SOAP:Envelope xmlns:SOAP="http://schemas.xmlsoap.org/soap/envelope/">
          <SOAP:Body xmlns:ns-a0661db7="http://www.example.org/simple/">
            <ns-a0661db7:sayHelloResponse xmlns:ns-a0661db7="http://www.example.org/simple/">
              <ns-a0661db7:out><![CDATA[hello Marc]]></ns-a0661db7:out>
            </ns-a0661db7:sayHelloResponse>
          </SOAP:Body>
        </SOAP:Envelope>
        ');
    }

    public function testSayHelloMulti()
    {
        $r = trim('
        <soapenv:Envelope
            xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:sim="http://www.example.org/simple/">
           <soapenv:Header/>
           <soapenv:Body>
              <parameters>
                 <sim:name>marc</sim:name>
                 <sim:title>mr</sim:title>
              </parameters>
              <extra-in>home</extra-in>
           </soapenv:Body>
        </soapenv:Envelope>');

        $h = function (InfoType $parameters, $extraIn) {
            var_dump($parameters, $extraIn);
            die();
            return "hello $in";
        };

        $request = new ServerRequest([], [], null, 'POST', DiactorosFactory::toStream($r),
            ['Soap-Action' => 'http://www.example.org/simple/multiHello']
        );
        $response = $this->server->handle($request, $h);
        $this->assertXmlStringEqualsXmlString((string)$response->getBody(), '
        <SOAP:Envelope xmlns:SOAP="http://schemas.xmlsoap.org/soap/envelope/">
          <SOAP:Body xmlns:ns-a0661db7="http://www.example.org/simple/">
            <ns-a0661db7:sayHelloResponse xmlns:ns-a0661db7="http://www.example.org/simple/">
              <ns-a0661db7:out><![CDATA[hello Marc]]></ns-a0661db7:out>
            </ns-a0661db7:sayHelloResponse>
          </SOAP:Body>
        </SOAP:Envelope>
        ');
    }

    public function testSayHelloCool()
    {
        $r = trim('
        <soapenv:Envelope
        xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
        xmlns:sim="http://www.example.org/simple/">
           <soapenv:Header/>
           <soapenv:Body>
              <sim:sayHelloCool>
                 <sim:in>
                    <sim:name>Marc</sim:name>
                    <sim:title>Mr</sim:title>
                 </sim:in>
              </sim:sayHelloCool>
           </soapenv:Body>
        </soapenv:Envelope>');

        $h = function (InfoType $in) {
            $greeting = new GreetingType();
            $greeting->setUser($in);
            $greeting->setPlace("rome");
            return $greeting;
        };

        $request = new ServerRequest([], [], null, 'POST', DiactorosFactory::toStream($r),
            ['Soap-Action' => 'http://www.example.org/simple/sayHelloCool']
        );
        $response = $this->server->handle($request, $h);
        echo $response->getBody();
        $this->assertXmlStringEqualsXmlString((string)$response->getBody(), '
        <SOAP:Envelope xmlns:SOAP="http://schemas.xmlsoap.org/soap/envelope/">
          <SOAP:Body xmlns:ns-a0661db7="http://www.example.org/simple/">
            <ns-a0661db7:sayHelloCoolResponse xmlns:ns-a0661db7="http://www.example.org/simple/">
              <ns-a0661db7:out>
                <ns-a0661db7:user>
                  <ns-a0661db7:name><![CDATA[Marc]]></ns-a0661db7:name>
                  <ns-a0661db7:title><![CDATA[Mr]]></ns-a0661db7:title>
                </ns-a0661db7:user>
                <ns-a0661db7:place><![CDATA[rome]]></ns-a0661db7:place>
              </ns-a0661db7:out>
            </ns-a0661db7:sayHelloCoolResponse>
          </SOAP:Body>
        </SOAP:Envelope>
        ');
    }
}