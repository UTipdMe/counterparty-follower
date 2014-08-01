The CounterpartyFollower component for UTipdMe.

A Counterparty send transaction follower. This is a standalone component of UTipdMe.

[![Build Status](https://travis-ci.org/UTipdMe/counterparty-follower.svg?branch=master)](https://travis-ci.org/UTipdMe/counterparty-follower)



```php

use Utipd\CounterpartyFollower\Follower;
use Utipd\CounterpartyFollower\FollowerSetup;
use Utipd\XCPDClient\Client;

// create a Counterparty RPC client
$xcpd_client = new Client($connection_string, $rpc_user, $rpc_password);

// init a mysql database connection
$pdo = new \PDO($db_connection_string, $db_user, $db_password);

// create the MySQL tables
if ($first_time) {
    $setup = new FollowerSetup($pdo, $db_name='xcpd_blocks');
    $stup->InitializeDatabase();
}

// build the follower and start at a recent block
$follower = new Follower($xcpd_client, $pdo);
$follower->setGenesisBlock(313500);

// setup the handlers
$follower->handleNewSend(function($send_details, $block_id) {
    echo "\$send_details:\n".json_encode($send_details, 192)."\n";
});

// listen forever
while (true) {
    $follower->processAnyNewBlocks();
    sleep(10);
}

```


### About Orphans

Orphaned blocks will happen. To handle those, you can use https://github.com/UTipdMe/native-follower


### Tips Accepted

BTC or Counterparty Tokens are gratefully accepted at 1Gccuf17nSeHf8iRu7QMHMayLaBRc8C9bh
