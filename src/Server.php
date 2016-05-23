<?php
namespace GoetasWebservices\SoapServices;

use ArgumentsResolver\InDepthArgumentsResolver;
use Doctrine\Common\Inflector\Inflector;
use Doctrine\Instantiator\Instantiator;
use GoetasWebservices\XML\SOAPReader\Soap\Operation;
use GoetasWebservices\XML\SOAPReader\Soap\OperationMessage;
use GoetasWebservices\XML\SOAPReader\Soap\Service;
use JMS\Serializer\Metadata\ClassMetadata;
use JMS\Serializer\Metadata\PropertyMetadata;
use JMS\Serializer\Serializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Server
{
    /**
     * @var Serializer
     */
    protected $serializer;

    /**
     * @var HttpMessageFactoryInterface
     */
    protected $httpFactory;

    /**
     * @var Service
     */
    protected $serviceDefinition;

    public function __construct(Service $serviceDefinition, Serializer $serializer, MessageFactoryInterfaceFactory $httpFactory)
    {
        $this->serializer = $serializer;
        $this->httpFactory = $httpFactory;
        $this->serviceDefinition = $serviceDefinition;
    }

    public function addNamespace($ns, $phpNamespace)
    {
        $this->namespaces[$ns] = $phpNamespace;
        return $this;
    }

    /**
     * @param ServerRequestInterface $request
     * @param Service $serviceDefinition
     * @param object $handler
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request, $handler)
    {
        $soapOperation = $this->findOperation($request, $this->serviceDefinition);
        $wsdlOperation = $soapOperation->getOperation();

        if (is_callable($handler)) {
            $function = $handler;
        } elseif (method_exists($handler, Inflector::camelize($wsdlOperation->getName()))) {
            $function = [$handler, Inflector::camelize($wsdlOperation->getName())];
        } else {
            throw new \Exception("Can not find a valid callback to invoke " . $wsdlOperation->getName());
        }

        $inputClass = $this->findClassName($soapOperation, $soapOperation->getInput(), 'Input');
        $message = $this->extractMessage($request, $inputClass);
        var_dump($message);
        $arguments = $this->expandArguments($message);

        $arguments = (new InDepthArgumentsResolver($function))->resolve($arguments);

        $result = call_user_func_array($function, $arguments);

        $class = $this->findClassName($soapOperation, $soapOperation->getOutput(), 'Output');
        $result = $this->wrapResult($result, $class);

        return $this->reply($result);
    }

    private function findOperation(ServerRequestInterface $request, Service $serviceDefinition)
    {
        $action = trim($request->getHeaderLine('Soap-Action'), '"');
        return $serviceDefinition->findByAction($action);
    }

    private function wrapResult($input, $class)
    {
        if (!$input instanceof $class) {
            $instantiator = new Instantiator();
            $factory = $this->serializer->getMetadataFactory();
            $previous = null;
            $previousProperty = null;
            $nextClass = $class;
            $originalInput = $input;
            $i = 0;
            while ($i++ < 4) {
                /**
                 * @var $classMetadata ClassMetadata
                 */
                if ($previousProperty && in_array($nextClass, ['string', 'float', 'integer', 'boolean'])) {
                    $previousProperty->setValue($previous, $originalInput);
                    break;
                }
                $classMetadata = $factory->getMetadataForClass($nextClass);
                if (!$classMetadata->propertyMetadata) {
                    throw new \Exception("Can not determine how to associate the message");
                }
                $instance = $instantiator->instantiate($classMetadata->name);
                /**
                 * @var $propertyMetadata PropertyMetadata
                 */
                $propertyMetadata = reset($classMetadata->propertyMetadata);

                if ($previous) {
                    $previousProperty->setValue($previous, $instance);
                } else {
                    $input = $instance;
                }

                if ($originalInput instanceof $propertyMetadata->type['name']) {
                    $propertyMetadata->setValue($instance, $originalInput);
                    break;
                }
                $previous = $instance;
                $nextClass = $propertyMetadata->type['name'];
                $previousProperty = $propertyMetadata;
            }
        }

        return $input;
    }

    private function getObjectProperties($object)
    {
        $ref = new \ReflectionObject($object);
        $args = [];
        do {
            foreach ($ref->getProperties() as $prop) {
                $prop->setAccessible(true);
                $args[$prop->getName()] = $prop->getValue($object);
            }
        } while ($ref = $ref->getParentClass());

        return $args;
    }

    private function smartAdd($arguments, $messageItems)
    {
        foreach ($messageItems as $name => $messageItem) {
            if (isset($arguments[$name])) {
                $arguments[] = $arguments[$name];
                $arguments[$name] = $messageItem;
            } else {
                $arguments[$name] = $messageItem;
            }
        }
        return $arguments;
    }

    private function expandArguments($envelope)
    {
        $arguments = [$envelope];
        $envelopeItems = $this->getObjectProperties($envelope);
        $arguments = $this->smartAdd($arguments, $envelopeItems);

        foreach ($envelopeItems as $envelopeItem) {
            $messageSubItems = $this->getObjectProperties($envelopeItem);
            $arguments = $this->smartAdd($arguments, $messageSubItems);
            foreach ($messageSubItems as $messageSubSubItems) {
                $messageSubSubItems = $this->getObjectProperties($messageSubSubItems);
                $arguments = $this->smartAdd($arguments, $messageSubSubItems);
            }
        }
        return $arguments;
    }

    protected function findClassName(
        Operation $operation,
        OperationMessage $operationMessage,
        $hint,
        $envelopePart = '\\Envelope\\Messages\\'
    )
    {
        return $this->namespaces[$operation->getOperation()->getDefinition()->getTargetNamespace()]
        . $envelopePart
        . Inflector::classify($operationMessage->getMessage()->getOperation()->getName())
        . $hint;
    }

    protected function extractMessage(ServerRequestInterface $request, $class)
    {
        $message = $this->serializer->deserialize((string)$request->getBody(), $class, 'xml');
        return $message;
    }

    protected function reply($envelope)
    {
        $message = $this->serializer->serialize($envelope, 'xml');
        $response = $this->httpFactory->getResponseMessage($message);
        return $response->withAddedHeader("Content-Type", "text/xml; charset=utf-8");
    }
}