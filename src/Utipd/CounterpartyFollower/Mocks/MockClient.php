<?php 

namespace Utipd\CounterpartyFollower\Mocks;

use Utipd\XCPDClient\Client;
use PDO;
use Exception;

/**
*       
*/
class MockClient extends Client
{

    protected $callbacks_map = null;

    function __construct($callbacks_map=[]) {
        $this->callbacks_map = $callbacks_map;
    }
    public function addCallback($method, Callable $function) {
        $this->callbacks_map[$method] = $function;
    }

    function __call($method, $arguments) {
        if ($this->callbacks_map AND isset($this->callbacks_map[$method])) {
            return call_user_func_array($this->callbacks_map[$method], $arguments);
        }

        throw new Exception("Mock method not implemented for $method", 1);
        
    }

    public function get_asset_info() {
        return [];
    }


}
