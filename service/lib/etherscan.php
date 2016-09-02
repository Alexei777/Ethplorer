<?php
/*!
 * Copyright 2016 Everex https://everex.io
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/profiler.php';

class Etherscan {

    protected $aSettings = array();

    /**
     * MongoDB collections.
     *
     * @var array
     */
    protected $dbs;

    /**
     * Singleton instance.
     *
     * @var Etherscan
     */
    protected static $oInstance;

    /**
     * Cache storage.
     *
     * @var evxCache
     */
    protected $oCache;

    /**
     * Constructor.
     *
     * @throws Exception
     */
    protected function __construct(array $aConfig){
        evxProfiler::checkpoint('START');
        $this->aSettings = $aConfig;
        $this->aSettings += array(
            "cacheDir" => dirname(__FILE__) . "/../cache/",
            "logsDir" => dirname(__FILE__) . "/../logs/",
        );

        $this->oCache = new evxCache($this->aSettings['cacheDir']);
        if(!isset($this->aSettings['mongo'])){
            throw new Exception("Mongo configuration not found");
        }
        if(!isset($this->aSettings['ethereum'])){
            throw new Exception("Ethereum configuration not found");
        }
        if(class_exists("MongoClient")){
            $oMongo = new MongoClient($this->aSettings['mongo']['server']);
            $oDB = $oMongo->{$this->aSettings['mongo']['dbName']};
            $this->dbs = array(
                'transactions' => $oDB->{"everex.eth.transactions"},
                'blocks'       => $oDB->{"everex.eth.blocks"},
                'contracts'    => $oDB->{"everex.eth.contracts"},
                'tokens'       => $oDB->{"everex.erc20.contracts"},
                'transfers'    => $oDB->{"everex.erc20.transfers"},
                'issuances'    => $oDB->{"everex.erc20.issuances"},
                'balances'     => $oDB->{"everex.erc20.balances"},
            );
        }else{
            throw new Exception("MongoClient class not found, php_mongo extension required");
        }
        // Get last block
        $lastblock = $this->getLastBlock();
        $this->oCache->store('lastBlock', $lastblock);
    }

    /**
     * Singleton getter.
     *
     * @return Ethereum
     */
    public static function db(array $aConfig = array()){
        if(is_null(self::$oInstance)){
            self::$oInstance = new Etherscan($aConfig);
        }
        return self::$oInstance;
    }

    /**
     * Returns true if provided string is a valid ethereum address.
     *
     * @param string $address  Address to check
     * @return bool
     */
    public function isValidAddress($address){
        return (is_string($address)) ? preg_match("/^0x[0-9a-f]{40}$/", $address) : false;
    }

    /**
     * Returns true if provided string is a valid ethereum tx hash.
     *
     * @param string  $hash  Hash to check
     * @return bool
     */
    public function isValidTransactionHash($hash){
        return (is_string($hash)) ? preg_match("/^0x[0-9a-f]{64}$/", $hash) : false;
    }

    /**
     * Returns advanced address details.
     *
     * @param string $address
     * @return array
     */
    public function getAddressDetails($address){
        $result = array(
            "isContract"    => false,
            "balance"       => $this->getBalance($address),
            "transfers"     => array()
        );
        $contract = $this->getContract($address);
        $token = false;
        if($contract){
            $result['isContract'] = true;
            $result['contract'] = $contract;
            if($token = $this->getToken($address)){
                $result["token"] = $token;
            }
        }
        if($result['isContract'] && isset($result['token'])){
            $result["transfers"] = $this->getContractTransfers($address);
            $result["issuances"] = $this->getContractIssuances($address);
        }
        if(!isset($result['token'])){
            // Get balances
            $result["tokens"] = array();
            $result["balances"] = $this->getAddressBalances($address);
            foreach($result["balances"] as $balance){
                $balanceToken = $this->getToken($balance["contract"]);
                if($balanceToken){
                    $result["tokens"][$balance["contract"]] = $balanceToken;
                }
            }
            $result["transfers"] = $this->getAddressTransfers($address);
        }
        return $result;
    }

    /**
     * Returns advanced transaction data.
     *
     * @param string  $hash  Transaction hash
     * @return array
     */
    public function getTransactionDetails($hash){
        $cache = 'tx-' . $hash;
        $result = $this->oCache->get($cache, false, true);
        if(false === $result){
            $tx = $this->getTransaction($hash);
            $result = array(
                "tx" => $tx,
                "contracts" => array()
            );
            if(isset($tx["creates"]) && $tx["creates"]){
                $result["contracts"][] = $tx["creates"];
            }
            $fromContract = $this->getContract($tx["from"]);
            if($fromContract){
                $result["contracts"][] = $tx["from"];
            }
            if(isset($tx["to"]) && $tx["to"]){
                $toContract = $this->getContract($tx["to"]);
                if($toContract){
                    $result["contracts"][] = $tx["to"];
                    if($token = $this->getToken($tx["to"])){
                        $result["token"] = $token;
                        $result["transfers"] = $this->getTransfers($hash);
                        $result["issuances"] = $this->getIssuances($hash);
                    }
                    if(is_array($result["issuances"]) && count($result["issuances"])){
                        $result["operation"] = $result["issuances"][0];
                    }elseif(is_array($result["transfers"]) && count($result["transfers"])){
                        $result["operation"] = $result["transfers"][0];
                    }
                }
            }
        }
        if(is_array($result) && is_array($tx)){
            $result['tx']['confirmations'] = $this->oCache->get('lastBlock') - $tx['blockNumber'];
        }
        return $result;
    }

    /**
     * Return address ETH balance.
     *
     * @param string  $address  Address
     * @return double
     */
    public function getBalance($address){
        // evxProfiler::checkpoint('getBalance START [address=' . $address . ']');
        $balance = $this->_callRPC('eth_getBalance', array($address, 'latest'));
        if(false !== $balance){
            $balance = hexdec(str_replace('0x', '', $balance)) / pow(10, 18);
        }
        // evxProfiler::checkpoint('getBalance FINISH [address=' . $address . ']');
        return $balance;
    }

    /**
     * Return transaction data by transaction hash.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getTransaction($tx){
        // evxProfiler::checkpoint('getTransaction START [hash=' . $tx . ']');
        $cursor = $this->dbs['transactions']->find(array("hash" => $tx));
        $result = $cursor->hasNext() ? $cursor->getNext() : false;
        if($result){
            unset($result["_id"]);
            $result['gasLimit'] = $result['gas'];
            unset($result["gas"]);
            $result['gasUsed'] = isset($result['receipt']) ? $result['receipt']['gasUsed'] : 0;
            $result['success'] = isset($result['receipt']) ? ($result['gasUsed'] < $result['gasLimit']) : true;
        }
        // evxProfiler::checkpoint('getTransaction FINISH [hash=' . $tx . ']');
        return $result;
    }

    /**
     * Returns list of transfers in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getTransfers($tx){
        // evxProfiler::checkpoint('getTransfer START [hash=' . $tx . ']');
        $cursor = $this->dbs['transfers']->find(array("transactionHash" => $tx));
        $result = array();
        while($cursor->hasNext()){
            $res = $cursor->getNext();
            unset($res["_id"]);
            $res["success"] = true;
            $res["type"] = "transfer";
            $result[] = $res;
        }
        // evxProfiler::checkpoint('getTransfer FINISH [hash=' . $tx . ']');
        return $result;
    }

    /**
     * Returns list of issuances in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getIssuances($tx){
        // evxProfiler::checkpoint('getIssuances START [hash=' . $tx . ']');
        $cursor = $this->dbs['issuances']->find(array("transactionHash" => $tx));
        $result = array();
        while($cursor->hasNext()){
            $res = $cursor->getNext();
            unset($res["_id"]);
            $res["success"] = true;
            $res["type"] = "issuance";
            $result[] = $res;
        }
        // evxProfiler::checkpoint('getIssuances FINISH [hash=' . $tx . ']');
        return $result;
    }

    /**
     * Returns list of known tokens.
     *
     * @param bool  $updateCache  Update cache from DB if true
     * @return array
     */
    public function getTokens($updateCache = false){
        $aResult = $updateCache ? false : $this->oCache->get('tokens', false, true);
        if(false === $aResult){
            $cursor = $this->dbs['tokens']->find()->sort(array("transfersCount" => -1));
            $aResult = array();
            foreach($cursor as $aToken){
                $address = $aToken["address"];
                unset($aToken["_id"]);
                $aResult[$address] = $aToken;
            }
            $this->oCache->save('tokens', $aResult);
        }
        return $aResult;
    }

    /**
     * Returns token data by contract address.
     *
     * @param string  $address  Token contract address
     * @return array
     */
    public function getToken($address){
        // evxProfiler::checkpoint('getToken START [address=' . $address . ']');
        $aTokens = $this->getTokens();
        $result = isset($aTokens[$address]) ? $aTokens[$address] : false;
        if($result) unset($result["_id"]);
        // evxProfiler::checkpoint('getToken FINISH [address=' . $address . ']');
        return $result;
    }

    /**
     * Returns contract data by contract address.
     *
     * @param string $address
     * @return array
     */
    public function getContract($address){
        // evxProfiler::checkpoint('getContract START [address=' . $address . ']');
        $cursor = $this->dbs['contracts']->find(array("address" => $address));
        $result = $cursor->hasNext() ? $cursor->getNext() : false;
        if($result) unset($result["_id"]);
        // evxProfiler::checkpoint('getContract FINISH [address=' . $address . ']');
        return $result;
    }

    /**
     * Returns list of contract transfers.
     *
     * @param string $address  Contract address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getContractTransfers($address, $limit = 10){
        return $this->getContractOperation('transfers', $address, $limit);
    }

    /**
     * Returns list of contract issuances.
     *
     * @param string $address  Contract address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getContractIssuances($address, $limit = 10){
        return $this->getContractOperation('issuances', $address, $limit);
    }

    /**
     * Returns last known mined block number.
     *
     * @return int
     */
    public function getLastBlock(){
        // evxProfiler::checkpoint('getLastBlock START');
        $cursor = $this->dbs['blocks']->find(array(), array('number' => true))->sort(array('number' => -1))->limit(1);
        $block = $cursor->getNext();
        // evxProfiler::checkpoint('getLastBlock FINISH');
        return $block && isset($block['number']) ? $block['number'] : false;
    }

    /**
     * Returns address token balances.
     *
     * @param string $address  Address
     * @param bool $withZero   Returns zero balances if true
     * @return array
     */
    public function getAddressBalances($address, $withZero = true){
        // evxProfiler::checkpoint('getAddressBalances START [address=' . $address . ']');
        $cursor = $this->dbs['balances']->find(array("address" => $address));
        $result = array();
        $fetches = 0;
        foreach($cursor as $balance){
            unset($balance["_id"]);
            // @todo: $withZero flag implementation
            $result[] = $balance;
            $fetches++;
        }
        // evxProfiler::checkpoint('getAddressBalances FINISH [address=' . $address . '] with ' . $fetches . ' fetches]');
        return $result;
    }

    /**
     * Returns list of transfers made by specified address.
     *
     * @param string $address  Address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getAddressTransfers($address, $limit = 10){
        // evxProfiler::checkpoint('getAddressTransfers START [address=' . $address . ', limit=' . $limit . ']');
        $cursor = $this->dbs['transfers']
            ->find(array('$or' => array(array("from" => $address), array("to" => $address))))
                ->sort(array("timestamp" => -1))
                ->limit($limit);
        $result = array();
        $fetches = 0;
        foreach($cursor as $transfer){
            unset($transfer["_id"]);
            $result[] = $transfer;
            $fetches++;
        }
        // evxProfiler::checkpoint('getAddressTransfers FINISH [address=' . $address . '] with ' . $fetches . ' fetches]');
        return $result;
    }

    /**
     * Returns contract operation data.
     *
     * @param string $type     Operation type
     * @param string $address  Contract address
     * @param string $limit    Maximum number of records
     * @return array
     */
    protected function getContractOperation($type, $address, $limit){
        // evxProfiler::checkpoint('getContract ' . $type . ' START [address=' . $address . ', limit=' . $limit . ']');
        $cursor = $this->dbs[$type]
            ->find(array("contract" => $address))
                ->sort(array("timestamp" => -1))
                ->limit($limit);
        $result = array();
        $fetches = 0;
        foreach($cursor as $transfer){
            unset($transfer["_id"]);
            $result[] = $transfer;
            $fetches++;
        }
        // evxProfiler::checkpoint('getContract ' . $type . ' FINISH [address=' . $address . '] with ' . $fetches . ' fetches]');
        return $result;
    }

    /**
     * JSON RPC request implementation.
     *
     * @param string $method  Method name
     * @param array $params   Parameters
     * @return array
     */
    protected function _callRPC($method, $params = array()){
        $data = array(
            'jsonrpc' => "2.0",
            'id'      => time(),
            'method'  => $method,
            'params'  => $params
        );
        $result = false;
        $json = json_encode($data);
        $ch = curl_init($this->aSettings['ethereum']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json))
        );
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $rjson = curl_exec($ch);
        if($rjson && (is_string($rjson)) && ('{' === $rjson[0])){
            $json = json_decode($rjson, JSON_OBJECT_AS_ARRAY);
            if(isset($json["result"])){
                $result = $json["result"];
            }
        }
        return $result;
    }

    /**
     * Store profile data on exit.
     */
    public function __destruct() {
        // evxProfiler::checkpoint('FINISH');
        // file_put_contents($this->aSettings['logsDir'] . '/profiler-' . microtime(true) . '.log', json_encode(evxProfiler::get(), JSON_PRETTY_PRINT));
    }
}