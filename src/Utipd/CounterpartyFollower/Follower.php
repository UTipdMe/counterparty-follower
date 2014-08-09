<?php 

namespace Utipd\CounterpartyFollower;

use PDO;
use Utipd\XCPDClient\Client;

/**
*       
*/
class Follower
{

    protected $db_connection = null;
    protected $new_send_callback_fn = null;
    protected $new_block_callback_fn = null;
    
    // no blocks before this are ever seen
    protected $genesis_block = 314170;

    function __construct(Client $xcpd_client, PDO $db_connection) {
        $this->xcpd_client = $xcpd_client;
        $this->db_connection = $db_connection;
    }

    public function setGenesisBlock($genesis_block) {
        $this->genesis_block = $genesis_block;
    }

    public function handleNewBlock(Callable $new_block_callback_fn) {
        $this->new_block_callback_fn = $new_block_callback_fn;
    }

    public function handleNewSend(Callable $new_send_callback_fn) {
        $this->new_send_callback_fn = $new_send_callback_fn;
    }

    public function processAnyNewBlocks($limit=null) {
        $last_block = $this->getLastProcessedBlock();
        if ($last_block === null) { $last_block = $this->genesis_block - 1; }

        $this->processBlocksNewerThan($last_block, $limit);
    }

    public function processOneNewBlock() {
        return $this->processAnyNewBlocks(1);
    }

    public function processBlocksNewerThan($last_processed_block, $limit=null) {
        $next_block_id = $last_processed_block + 1;

        $counterpartyd_block_height = $this->getCounterpartyDBlockHeight();
        if (!$counterpartyd_block_height) { throw new Exception("Could not get counterpartyd block height.  Last result was:".json_encode($this->last_result, 192), 1); }

        $last_block_processed = 0;
        $processed_count = 0;
        while ($next_block_id <= $counterpartyd_block_height) {
            // mark the block as seen
            $this->markBlockAsSeen($next_block_id);

            // process the block
            $this->processBlock($next_block_id);

            // mark the block as processed
            $this->markBlockAsProcessed($next_block_id);
            $last_block_processed = $next_block_id;

            // clear mempool, because a new block was processed
            $this->clearMempool();

            ++$next_block_id;
            if ($next_block_id > $counterpartyd_block_height) {
                // reload the bitcoin block height in case this took a long time
                $counterpartyd_block_height = $this->getCounterpartyDBlockHeight();
            }

            // check for limit
            ++$processed_count;
            if ($limit !== null) {
                if ($processed_count >= $limit) { break; }
            }
        }

        // if we are caught up, process mempool transactions
        if ($last_block_processed == $counterpartyd_block_height) {
            $this->processMempoolTransactions();
        }
    }

    public function processBlock($block_id) {
        $this->processNewBlock($block_id);

        // get sends from counterpartyd
        $sends = $this->xcpd_client->get_sends(["start_block" => $block_id, "end_block" => $block_id]);
        
        // process block data
        if ($sends) {
            foreach($sends as $send) {
                $this->processSend($send, $block_id);
            }
        }
    }

    public function getLastProcessedBlock() {
        $sql = "SELECT MAX(blockId) AS blockId FROM blocks WHERE status=?";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute(['processed']);
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            return $row['blockId'];
        }
        return null;
    }


    protected function processNewBlock($block_id) {
        if ($this->new_block_callback_fn) {
            call_user_func($this->new_block_callback_fn, $block_id);
        }
    }

    protected function processSend($send_data, $block_id, $is_mempool=false) {
        // handle the send
        if ($this->new_send_callback_fn) {
            // add asset info for convenience
            $send_data['assetInfo'] = $this->getAssetInfo($send_data['asset']);
            call_user_func($this->new_send_callback_fn, $send_data, $block_id, $is_mempool);
        }
    }

    protected function getBitcoinBlockHeight() {
        $this->last_result = $this->xcpd_client->get_running_info([]);
        return $this->last_result['bitcoin_block_count'];
    }
    protected function getCounterpartyDBlockHeight() {
        $this->last_result = $this->xcpd_client->get_running_info([]);
        return $this->last_result['last_block']['block_index'];
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


    protected function getAssetInfo($token) {
        if (!isset($this->asset_info[$token])) {
            $assets = $this->xcpd_client->get_asset_info(['assets' => [$token]]);
            $this->asset_info[$token] = $assets[0];
        }
        return $this->asset_info[$token];
    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // mempool
    
    protected function clearMempool() {
        $sql = "TRUNCATE mempool";
        $sth = $this->db_connection->exec($sql);
    }

    protected function processMempoolTransactions() {
        // json={"filters": {"field": "category", "op": "==", "value": "sends"}}
        $mempool_txs = $this->xcpd_client->get_mempool(["json" => json_encode(['filters' => ['field' => 'category', 'op' => '==', 'value' => 'sends']])]);

        // load all processed mempool hashes
        $mempool_transactions_processed = $this->getAllMempoolTransactionsMap();

        foreach($mempool_txs as $mempool_tx) {
            // if already processed, skip it
            if (isset($mempool_transactions_processed[$mempool_tx['tx_hash']])) { continue; }

            // decode the bindings attribute
            $mempool_send = json_decode($mempool_tx['bindings'], true);

            // include the timestamp and process
            $mempool_send['timestamp'] = $mempool_tx['timestamp'];
            $this->processSend($mempool_send, null, true);

            // mark as processed
            $this->markMempoolTransactionAsProcessed($mempool_send['tx_hash'], $mempool_send['timestamp']);
        }
    }


    protected function getAllMempoolTransactionsMap() {
        $mempool_transactions_map = [];
        $sql = "SELECT hash FROM mempool";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute();
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $mempool_transactions_map[$row['hash']] = true;
        }
        return $mempool_transactions_map;
    }

    protected function markMempoolTransactionAsProcessed($hash, $timestamp) {
        $sql = "REPLACE INTO mempool VALUES (?,?)";
        $sth = $this->db_connection->prepare($sql);
        $result = $sth->execute([$hash, $timestamp]);
    }


}
