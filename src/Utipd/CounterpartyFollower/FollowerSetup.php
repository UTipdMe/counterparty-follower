<?php 

namespace Utipd\CounterpartyFollower;

use PDO;

/**
*       
*/
class FollowerSetup
{

    protected $db_connection = null;
    
    function __construct(PDO $db_connection, $db_name) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
    }


    public function processAnyNewBlocks() {
        $this->getLastBlock();
    }


    public function initializeAndEraseDatabase() {
        $this->eraseDatabase();
        $this->InitializeDatabase();
    }

    public function eraseDatabase() {

        ////////////////////////////////////////////////////////////////////////
        // db

        $this->exec("CREATE DATABASE IF NOT EXISTS `{$this->db_name}`;");
        $this->exec("use `{$this->db_name}`;");

        ////////////////////////////////////////////////////////////////////////
        // blocks and mempool

        $this->exec("DROP TABLE IF EXISTS `blocks`;");
        $this->exec("DROP TABLE IF EXISTS `mempool`;");

    } 

    public function InitializeDatabase() {

        ////////////////////////////////////////////////////////////////////////
        // db

        $this->exec("CREATE DATABASE IF NOT EXISTS `{$this->db_name}`;");
        $this->exec("use `{$this->db_name}`;");


        ////////////////////////////////////////////////////////////////////////
        // blocks and mempool
        
        $this->exec($_t = <<<EOT
CREATE TABLE IF NOT EXISTS `blocks` (
    `blockId`   int(11) unsigned NOT NULL,
    `status`    varchar(32) NOT NULL DEFAULT '',
    `timestamp` int(11) unsigned NOT NULL,
    PRIMARY KEY (`blockId`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
EOT
        );

        $this->exec($_t = <<<EOT
CREATE TABLE IF NOT EXISTS `mempool` (
    `hash`      varbinary(64) NOT NULL DEFAULT '',
    `timestamp` int(11) unsigned NOT NULL,
    PRIMARY KEY (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
EOT
        );

    }

    protected function exec($sql) {
        $result = $this->db_connection->exec($sql);
    }

}
