<?php

use Utipd\CounterpartyFollower\Follower;
use Utipd\CounterpartyFollower\FollowerSetup;
use Utipd\CounterpartyFollower\Mocks\MockClient;
use \Exception;
use \PHPUnit_Framework_Assert as PHPUnit;

/*
* 
*/
class FollowerBlocksTest extends \PHPUnit_Framework_TestCase
{


    public function testProcessBlocks() {
        $this->getFollowerSetup()->initializeAndEraseDatabase();
        $this->getMockXCPDClient()
            ->addCallback('get_running_info', function() {
                return ['bitcoin_block_count' => 313362, 'last_block' => ['block_index' => 313362]];
            })
            ->addCallback('get_sends', function($vars) {
                if ($vars['start_block'] == 313360) { return [$this->getSampleBlocks()[0]]; }
                if ($vars['start_block'] == 313361) { return [$this->getSampleBlocks()[1]]; }
                // no sends
                return [];
            });

        $follower = $this->getFollower();
        $found_send_tx_map = [];
        $follower->handleNewSend(function($send_vars) use (&$found_send_tx_map) {
            $found_send_tx_map[$send_vars['tx_index']] = $send_vars;
        });
        $follower->setGenesisBlock(313360);
        $follower->processAnyNewBlocks();

        PHPUnit::assertArrayHasKey(100000, $found_send_tx_map);
        PHPUnit::assertArrayHasKey(100001, $found_send_tx_map);
        PHPUnit::assertEquals('source2', $found_send_tx_map[100001]['source']);
    }

    public function testErrorsWhileLoadingBlocks() {
        $this->getFollowerSetup()->initializeAndEraseDatabase();
        $this->getMockXCPDClient()->addCallback('get_running_info', function() {
            return ['bitcoin_block_count' => 313362, 'last_block' => ['block_index' => 313362]];
        });

        $xcpd_failed_once = false;
        $this->getMockXCPDClient()->addCallback('get_sends', function($vars) use (&$xcpd_failed_once) {
            if ($vars['start_block'] == 313360) { return [$this->getSampleBlocks()[0]]; }
            if ($vars['start_block'] == 313361) {
                if (!$xcpd_failed_once) {
                    $xcpd_failed_once = true;
                    throw new Exception("Test error", 1);
                }
                return [$this->getSampleBlocks()[1]];
            }
            // no sends
            return [];
        });

        $follower = $this->getFollower();
        $found_send_tx_map = [];
        $attempts = 0;
        $follower->handleNewSend(function($send_vars) use (&$found_send_tx_map, &$attempts) {
            ++$attempts;
            $found_send_tx_map[$send_vars['tx_index']] = $send_vars;
        });
        $follower->setGenesisBlock(313360);

        // run twice
        try {
            $follower->processAnyNewBlocks();
        } catch (Exception $e) { }
        $follower->processAnyNewBlocks();

        PHPUnit::assertArrayHasKey(100000, $found_send_tx_map);
        PHPUnit::assertArrayHasKey(100001, $found_send_tx_map);
        PHPUnit::assertEquals('source2', $found_send_tx_map[100001]['source']);
        PHPUnit::assertEquals(2, $attempts);
    }

    public function testErrorsWhileProcessingBlocks() {
        $this->getFollowerSetup()->initializeAndEraseDatabase();
        $this->getMockXCPDClient()
            ->addCallback('get_running_info', function() {
                return ['bitcoin_block_count' => 313362, 'last_block' => ['block_index' => 313362]];
            })
            ->addCallback('get_sends', function($vars) {
                if ($vars['start_block'] == 313360) { return [$this->getSampleBlocks()[0]]; }
                if ($vars['start_block'] == 313361) { return [$this->getSampleBlocks()[1]]; }
                // no sends
                return [];
            });

        $follower = $this->getFollower();

        $found_send_tx_map = [];
        $attempts = 0;
        $handle_failed_once = false;
        $follower->handleNewSend(function($send_vars, $block_id) use (&$found_send_tx_map, &$attempts, &$handle_failed_once) {
            ++$attempts;

            if ($block_id == 313361) {
                if (!$handle_failed_once) {
                    $handle_failed_once = true;
                    throw new Exception("Test error", 1);
                }
            }

            $found_send_tx_map[$send_vars['tx_index']] = $send_vars;
        });


        // run twice
        $follower->setGenesisBlock(313360);
        try {
            $follower->processAnyNewBlocks();
        } catch (Exception $e) { }
        $follower->processAnyNewBlocks();


        PHPUnit::assertArrayHasKey(100000, $found_send_tx_map);
        PHPUnit::assertArrayHasKey(100001, $found_send_tx_map);
        PHPUnit::assertEquals('source2', $found_send_tx_map[100001]['source']);
        PHPUnit::assertEquals(3, $attempts);
    }


    public function testMempool() {
        $this->getFollowerSetup()->initializeAndEraseDatabase();
        $this->getMockXCPDClient()
            ->addCallback('get_running_info', function() {
                return ['bitcoin_block_count' => 313362, 'last_block' => ['block_index' => 313362]];
            })
            ->addCallback('get_sends', function($vars) {
                if ($vars['start_block'] == 313360) { return [$this->getSampleBlocks()[0]]; }
                if ($vars['start_block'] == 313361) { return [$this->getSampleBlocks()[1]]; }
                // no sends
                return [];
            })->addCallback('get_mempool', function() {
                $_j = <<<EOT
[
    {
        "bindings": "{\"asset\": \"MYASSETONE\", \"destination\": \"dest01\", \"quantity\": 10, \"source\": \"13UxmTs2Ad2CpMGvLJu3tSV2YVuiNcVkvn\", \"tx_hash\": \"c324e62d0ba17f42a774b9b28114217c777914a4b6dd0d41811217cffb8c40a6\"}",
        "category": "sends",
        "command": "insert",
        "timestamp": 1407585745,
        "tx_hash": "mempool01txhash"
    }
]
EOT;
                return json_decode($_j, true);
            });


        $follower = $this->getFollower();
        $found_send_tx_map = ['normal' => [], 'mempool' => []];
        $mempool_transactions_processed = 0;
        $follower->handleNewSend(function($send_vars, $block_id, $is_mempool) use (&$found_send_tx_map, &$mempool_transactions_processed) {
            if ($is_mempool) {
                ++$mempool_transactions_processed;
                $found_send_tx_map['mempool'][] = $send_vars;
            } else {
                $found_send_tx_map['normal'][$send_vars['tx_index']] = $send_vars;
            }
        });
        $follower->setGenesisBlock(313360);

        // run three times
        $follower->processAnyNewBlocks(3);

        // echo "\$found_send_tx_map:\n".json_encode($found_send_tx_map, 192)."\n";
        PHPUnit::assertArrayHasKey(100000, $found_send_tx_map['normal']);
        PHPUnit::assertArrayHasKey(100001, $found_send_tx_map['normal']);
        PHPUnit::assertEquals('source2', $found_send_tx_map['normal'][100001]['source']);

        PHPUnit::assertEquals(1, $mempool_transactions_processed);
        PHPUnit::assertCount(1, $found_send_tx_map['mempool']);

        // make sure mempool table has one entry
        $db_connection = $this->getPDO();
        $sql = "SELECT hash FROM mempool";
        $sth = $db_connection->prepare($sql);
        $result = $sth->execute();
        PHPUnit::assertEquals(1, $sth->rowCount());



        // now clear the mempool by processing a new block
        $this->getMockXCPDClient()
            ->addCallback('get_running_info', function() {
                return ['bitcoin_block_count' => 313363, 'last_block' => ['block_index' => 313363]];
            })
            ->addCallback('get_sends', function($vars) {
                return [];
            })->addCallback('get_mempool', function() {
                return [];
            });
        $follower->processAnyNewBlocks(1);

        // make sure mempool table is cleared
        $db_connection = $this->getPDO();
        $sql = "SELECT hash FROM mempool";
        $sth = $db_connection->prepare($sql);
        $result = $sth->execute();
        PHPUnit::assertEquals(0, $sth->rowCount());

    }

// {
//     "asset": "MYASSETONE",
//     "destination": "dest01",
//     "quantity": 10,
//     "source": "13UxmTs2Ad2CpMGvLJu3tSV2YVuiNcVkvn",
//     "tx_hash": "c324e62d0ba17f42a774b9b28114217c777914a4b6dd0d41811217cffb8c40a6"
// }

    ////////////////////////////////////////////////////////////////////////
    // credit

    public function testProcessCredit() {
        $this->getFollowerSetup()->initializeAndEraseDatabase();
        $this->getMockXCPDClient()
            ->addCallback('get_running_info', function() {
                return ['bitcoin_block_count' => 313362, 'last_block' => ['block_index' => 313362]];
            })
            ->addCallback('get_credits', function($vars) {
                if ($vars['start_block'] == 313360) { return [$this->getSampleCreditBlocks()[0]]; }
                if ($vars['start_block'] == 313361) { return [$this->getSampleCreditBlocks()[1]]; }
                // no credits
                return [];
            });

        $follower = $this->getFollower();
        $found_credit_tx_map = [];
        $follower->handleNewCredit(function($credit_vars) use (&$found_credit_tx_map) {
            $found_credit_tx_map[$credit_vars['block_index']] = $credit_vars;
        });
        $follower->setGenesisBlock(313360);
        $follower->processAnyNewBlocks();

        PHPUnit::assertArrayHasKey(313360, $found_credit_tx_map);
        PHPUnit::assertEquals('recipient01', $found_credit_tx_map[313360]['address']);

        // credit for 313361 had an "event", so it is not a dividend credit
        PHPUnit::assertArrayNotHasKey(313361, $found_credit_tx_map);
    }

    public function testProcessMempoolCredit() {
        $this->getFollowerSetup()->initializeAndEraseDatabase();
        $this->getMockXCPDClient()
            ->addCallback('get_running_info', function() {
                return ['bitcoin_block_count' => 313362, 'last_block' => ['block_index' => 313362]];
            })->addCallback('get_mempool', function($params) {
                $_j = <<<EOT
[
    {
        "timestamp": 1409708458,
        "command": "insert",
        "category": "credits",
        "tx_hash": null,
        "bindings": "{\"action\": null, \"address\": \"mrecipient01\", \"asset\": \"XBTC\", \"block_index\": 9999999, \"event\": null, \"quantity\": 500000000}"
    }
]
EOT;
                return json_decode($_j, true);
            });



        $follower = $this->getFollower();
        $found_credit_txs = [];
        $follower->handleNewCredit(function($credit_vars, $bid, $is_mempool) use (&$found_credit_txs) {
            PHPUnit::assertTrue($is_mempool);
            $found_credit_txs[] = $credit_vars;
        });
        $follower->setGenesisBlock(313360);
        $follower->processAnyNewBlocks();

        PHPUnit::assertCount(1, $found_credit_txs);
        PHPUnit::assertEquals('mrecipient01', $found_credit_txs[0]['address']);
    }


    ////////////////////////////////////////////////////////////////////////
    
    
    protected function getFollower() {
        list($db_connection_string, $db_user, $db_password) = $this->buildConnectionInfo();
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new Follower($this->getMockXCPDClient(), $pdo);
    }

    protected function getFollowerSetup() {
        list($db_connection_string, $db_user, $db_password, $db_name) = $this->buildConnectionInfo(false);
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new FollowerSetup($pdo, $db_name);
    }

    protected function getPDO() {
        list($db_connection_string, $db_user, $db_password, $db_name) = $this->buildConnectionInfo(true);
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
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

    protected function getMockXCPDClient() {
        if (!isset($this->xcpd_client)) {
            $this->xcpd_client = new MockClient();
            $this->xcpd_client->addCallback('get_sends', function($vars) { return []; });
            $this->xcpd_client->addCallback('get_credits', function($vars) { return []; });

        }
        return $this->xcpd_client;
    }

    protected function getSampleBlocks() {
        return [
            [
                // fake info
                "block_index" => 313360,
                "tx_index"    => 100000,
                "source"      => "1NFeBp9s5aQ1iZ26uWyiK2AYUXHxs7bFmB",
                "destination" => "1B7FpKyJ4LtcqfxqR2zUtquSupcPnLuZpk",
                "asset"       => "XCP",
                "status"      => "valid",
                "quantity"    => 490000000,
                "tx_hash"     => "278afe117b744fc9e23c0198e9555a129b3b72f974503e81d6fb5df4bc453688",
            ],
            [
                // fake info
                "block_index" => 313361,
                "tx_index"    => 100001,
                "source"      => "source2",
                "destination" => "dest2",
                "asset"       => "ASSET2",
                "status"      => "valid",
                "quantity"    => 490000000,
                "tx_hash"     => "hash2",
            ]
        ];
    }

    protected function getSampleCreditBlocks() {
        return [
            [
                // fake info
                "block_index" => 313360,
                "address"     => "recipient01",
                "asset"       => "XBTC",
                "quantity"    => 490000000,
                "event"       => null,
            ],
            [
                // fake info
                "block_index" => 313361,
                "address"     => "recipient01",
                "asset"       => "LTBCOIN",
                "quantity"    => 490000000,
                "event"       => "278afe117b744fc9e23c0198e9555a129b3b72f974503e81d6fb5df4bc453688",
            ]
        ];
    }

}
