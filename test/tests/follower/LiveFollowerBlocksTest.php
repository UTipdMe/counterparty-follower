<?php

use Utipd\CounterpartyFollower\Follower;
use Utipd\CounterpartyFollower\FollowerSetup;
use Utipd\XCPDClient\Client;
use \Exception;
use \PHPUnit_Framework_Assert as PHPUnit;

/*
* 
*/
class LiveFollowerBlocksTest extends \PHPUnit_Framework_TestCase
{


    public function testLiveProcessBlocks() {
        $this->markTestIncomplete();

        $this->getFollowerSetup()->initializeAndEraseDatabase();


        // $this->markTestIncomplete();
        $client = $this->getXCPDClient();
        $info = $client->get_running_info([]);
        $bitcoin_block_count = $info['bitcoin_block_count'];
        echo "\n\$bitcoin_block_count=$bitcoin_block_count\n";

        $follower = $this->getFollower();
        $follower->setGenesisBlock($bitcoin_block_count - 15);

        $found_send_tx_map = [];
        $blocks_seen_count = 0;
        $follower->handleNewSend(function($send_vars, $block_id) use (&$found_send_tx_map, &$blocks_seen_count) {
            ++$blocks_seen_count;
            // echo json_encode($send_vars, 192)."\n";
            $found_send_tx_map[$send_vars['tx_index']] = $send_vars;
        });

        $follower->processAnyNewBlocks();

        PHPUnit::assertGreaterThan(1, $blocks_seen_count);

    }

    ////////////////////////////////////////////////////////////////////////

    protected function getFollower() {
        list($db_connection_string, $db_user, $db_password) = $this->buildConnectionInfo();
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new Follower($this->getXCPDClient(), $pdo);
    }

    protected function getFollowerSetup() {
        list($db_connection_string, $db_user, $db_password, $db_name) = $this->buildConnectionInfo(false);
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new FollowerSetup($pdo, $db_name);
    }

    protected function buildConnectionInfo($with_db=true) {
        $db_name = getenv('DB_NAME');
        if (!$db_name) { throw new Exception("No DB_NAME env var found", 1); }
        $db_host = getenv('DB_HOST');
        if (!$db_host) { throw new Exception("No DB_HOST env var found", 1); }
        $db_port = getenv('DB_PORT');
        if (!$db_port) { throw new Exception("No DB_PORT env var found", 1); }
        $db_user = getenv('DB_USER');
        if (!$db_user) { throw new Exception("No DB_USER env var found", 1); }
        $db_password = getenv('DB_PASSWORD');
        if ($db_password === false) { throw new Exception("No DB_PASSWORD env var found", 1); }

        if ($with_db) {
            $db_connection_string = "mysql:dbname={$db_name};host={$db_host};port={$db_port}";
        } else {
            $db_connection_string = "mysql:host={$db_host};port={$db_port}";
        }

        return [$db_connection_string, $db_user, $db_password, $db_name];
    }

    protected function getXCPDClient() {
        if (!isset($this->xcpd_client)) {
            $connection_string = getenv('XCPD_CONNECTION_STRING') ?: 'http://localhost:4000';
            $rpc_user = getenv('XCPD_RPC_USER') ?: null;
            $rpc_password = getenv('XCPD_RPC_PASSWORD') ?: null;
            $this->xcpd_client = new Client($connection_string, $rpc_user, $rpc_password);
        }
        return $this->xcpd_client;
    }


}
