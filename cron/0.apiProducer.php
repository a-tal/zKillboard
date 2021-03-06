<?php

for ($i = 0; $i < 30; ++$i) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        exit();
    }
    if ($pid == 0) {
        break;
    }
}


require_once '../init.php';

$apis = $mdb->getCollection('apis');
$information = $mdb->getCollection('information');
$tqApis = new RedisTimeQueue('tqApis', 9600);
$tqApiChars = new RedisTimeQueue('tqApiChars');

$timer = new Timer();
$requestNum = 0;

$numApis = $tqApis->size();
if ($i >= ($numApis / 100) + 1) exit();

while ($timer->stop() <= 58000) {
    $id = $tqApis->next();
    if ($id !== null) {
	$row = $mdb->findDoc('apis', ['_id' => $id]);
        $keyID = $row['keyID'];
        $vCode = $row['vCode'];
        $userID = $row['userID'];
	$errorCode = (int) @$row['errorCode'];

	\Pheal\Core\Config::getInstance()->http_user_agent = "API Fetcher for https://$baseAddr";
	\Pheal\Core\Config::getInstance()->http_post = false;
	\Pheal\Core\Config::getInstance()->http_keepalive = true; // default 15 seconds
	\Pheal\Core\Config::getInstance()->http_keepalive = 10; // KeepAliveTimeout in seconds
	\Pheal\Core\Config::getInstance()->http_timeout = 30;
	\Pheal\Core\Config::getInstance()->api_customkeys = true;

	$pheal = new \Pheal\Pheal($keyID, $vCode);
	try {
		$mdb->set('apis', $row, ['lastApiUpdate' => $mdb->now()]);
		$apiKeyInfo = $pheal->ApiKeyInfo();
		if ($errorCode != 0) $mdb->set('apis', $row, ['errorCode' => 0]);
	} catch (Exception $ex) {
		$tqApis->remove($id); // Problem with api the key, remove it from rotation
		$errorCode = (int) $ex->getCode();
		if ($errorCode == 904) {
			Util::out("(apiProducer) 904'ed");
			exit();
		}
		if ($errorCode == 28) {
			Util::out('(apiProducer) API Server timeout');
			exit();
		}
		if ($errorCode != 221 && $debug) {
			Util::out("(apiProducer) Error Validating $keyID: ".$ex->getCode().' '.$ex->getMessage());
		}
		$apis->update(['keyID' => $keyID, 'vCode' => $vCode], ['$set' => ['errorCode' => $errorCode]]);
		sleep(3);
		continue;
	}

	$key = @$apiKeyInfo->key;
	$accessMask = @$key->accessMask;
	$characterIDs = array();

	foreach ($apiKeyInfo->key->characters as $character) {
		$characterID = (int) $character->characterID;
		$redis->setex("tq:keyID:$keyID:$characterID", 86400, true);
		$characterIDs[] = $characterID;

		// Make sure we have the names and id's in the information table
		$mdb->insertUpdate('information', ['type' => 'corporationID', 'id' => ((int) $character->corporationID)], ['name' => ((string) $character->corporationName)]);
		$mdb->insertUpdate('information', ['type' => 'characterID', 'id' => ((int) $characterID)], ['name' => ((string) $character->characterName), 'corporationID' => ((int) $character->corporationID)]);

		$type = $apiKeyInfo->key->type;
		if ($debug) {
			Util::out("Adding $keyID $characterID $type $vCode");
		}

		$char = ['keyID' => $keyID, 'vCode' => $vCode, 'characterID' => $characterID, 'type' => $type, 'userID' => $userID];
		if ($accessMask & 256) $tqApiChars->add($char);
	}

	if (sizeof($characterIDs) == 1) {
		$charID = $characterIDs[0];
		$mdb->set('apis', $row, ['userID' => (int) $charID]);
	} else {
		$scores = [];
		foreach ($characterIDs as $charID) {
			$kills = $mdb->findField('statistics', 'shipsDestroyed', ['type' => 'characterID', 'id' => (int) $charID]);
			$scores[$charID] = (int) $kills;
		}
		arsort($scores);
		reset($scores);
		$charID = key($scores);
		$mdb->set('apis', $row, ['userID' => (int) $charID]);
	}
    }
    sleep(1);
}
