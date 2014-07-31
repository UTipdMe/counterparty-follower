<?php 

namespace Utipd\CounterpartyFollower;

use Utipd\XCPDClient\Client;
use PDO;

/**
*       
*/
class Follower
{

    protected $db_connection = null;
    protected $new_send_callback_fn = null;
    
    // no blocks before this are ever seen
    protected $genesis_block = 313364;

    function __construct(Client $xcpd_client, PDO $db_connection) {
        $this->xcpd_client = $xcpd_client;
        $this->db_connection = $db_connection;
    }

    public function setGenesisBlock($genesis_block) {
        $this->genesis_block = $genesis_block;
    }

    public function handleNewSend(Callable $new_send_callback_fn) {
        $this->new_send_callback_fn = $new_send_callback_fn;
    }

    public function processAnyNewBlocks() {
        $last_block = $this->getLastProcessedBlock();
        if ($last_block === null) { $last_block = $this->genesis_block - 1; }

        $this->processBlocksNewerThan($last_block);
    }

    public function processBlocksNewerThan($last_processed_block) {
        $next_block_id = $last_processed_block + 1;

        $bitcoin_block_height = $this->getBitcoinBlockHeight();
        while ($next_block_id <= $bitcoin_block_height) {

            // mark the block as seen
            $this->markBlockAsSeen($next_block_id);

            // process the block
            $this->processBlock($next_block_id);

            // mark the block as processed
            $this->markBlockAsProcessed($next_block_id);

            ++$next_block_id;
            if ($next_block_id > $bitcoin_block_height) {
                // reload the bitcoin block height in case this took a long time
                $bitcoin_block_height = $this->getBitcoinBlockHeight();
            }
        }
    }

    public function processBlock($block_id) {
        // get block from counterpartyd
        $sends = $this->xcpd_client->get_sends(["start_block" => $block_id, "end_block" => $block_id]);
        // echo "\$sends=".json_encode($sends, 192)."\n";
        
        // process block data
        if ($sends) {
            foreach($sends as $send) {
                $this->processSend($send, $block_id);
            }
        }
    }


    protected function processSend($block_data, $block_id) {

        // handle the send
        if ($this->new_send_callback_fn) {
            call_user_func($this->new_send_callback_fn, $block_data, $block_id);
        }
    }

    protected function getBitcoinBlockHeight() {
        $info = $this->xcpd_client->get_running_info([]);
        return $info['bitcoin_block_count'];
    }

    protected function getLastProcessedBlock() {
        $sql = "SELECT MAX(blockId) AS blockId FROM blocks WHERE status=?";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute(['processed']);
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            return $row['blockId'];
        }
        return null;
    }

    protected function markBlockAsSeen($block_id) {
        $sql = "REPLACE INTO blocks VALUES (?,?,?)";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute([$block_id, 'seen', time()]);
    }

    protected function markBlockAsProcessed($block_id) {
        $sql = "REPLACE INTO blocks VALUES (?,?,?)";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute([$block_id, 'processed', time()]);
    }




}
