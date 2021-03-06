<?php

require_once('common.php');
require_once('config.php');
require_once('config.local.php');

require_once(SYSTEM . 'functions.php');
require_once(SYSTEM . 'init.php');
require_once(SYSTEM . 'status.php');

# error function
function sendError($msg){
	$ret = [];
	$ret["errorCode"] = 3;
	$ret["errorMessage"] = $msg;

	die(json_encode($ret));
}

$request = file_get_contents('php://input');
$result = json_decode($request);
$action = isset($result->type) ? $result->type : '';
switch ($action) {

	case 'cacheinfo':
		die(json_encode([
			'playersonline' => $status['players'],
			'twitchstreams' => 0,
			'twitchviewer' => 0,
			'gamingyoutubestreams' => 0,
			'gamingyoutubeviewer' => 0
		]));
	break;

	case 'eventschedule':
		die(json_encode([
			'eventlist' => []
		]));
	break;

	case 'boostedcreature':
		die(json_encode([
			'boostedcreature' => false,
		]));
	break;

	case 'login':

		// check if cast is enable and accountname is cast
		$casting = $config['lua']['enableLiveCasting'] && $result->accountname == 'cast';
		if (!$casting && !$result->password) {
			sendError('Account name or password is not correct.');
		}

		$port = $config['lua']['gameProtocolPort'];
		// change port if is accountname is cast
		if ($casting) {
			$port = $config['lua']['liveCastPort'];
		}

		// default world info
		$world = [
			'id' => 0,
			'name' => $config['lua']['serverName'],
			'externaladdressprotected' => $config['lua']['ip'],
			'externalportprotected' => $port,
			'externaladdressunprotected' => $config['lua']['ip'],
			'externalportunprotected' => $port,
			'previewstate' => 0,
			'location' => 'BRA', // BRA, EUR, USA
			'anticheatprotection' => false,
			'pvptype' => array_search($config['lua']['worldType'], ['pvp', 'no-pvp', 'pvp-enforced']),
			'istournamentworld' => false,
			'restrictedstore' => false,
			'currenttournamentphase' => 2
		];

		$characters = [];
		$account = null;

		// common columns
		$columns = 'name, level, sex, vocation, looktype, lookhead, lookbody, looklegs, lookfeet, lookaddons, deleted, lastlogin';
		if ($casting) {
			// get players casting
			$casters = $db->query("select {$columns} from live_casts inner join players  on player_id = id")->fetchAll();

			if (!count($casters)) {
				sendError('There is no live casts right now!');
			}

			foreach ($casters as $caster) {
				$characters[] = create_char($caster);
			}
		} else {
			$account = new OTS_Account();
			$account->find($result->accountname);

			$config_salt_enabled = fieldExist('salt', 'accounts');
			$current_password = encrypt(($config_salt_enabled ? $account->getCustomField('salt') : '') . $result->password);

			if (!$account->isLoaded() || $account->getPassword() != $current_password) {
				sendError('Account name or password is not correct.');
			}

			$players = $db->query("select {$columns} from players where account_id = " . $account->getId())->fetchAll();
			foreach ($players as $player) {
				$characters[] = create_char($player);
			}
		}

		$worlds = [$world];
		$playdata = compact('worlds', 'characters');
		$session = [
			'sessionkey' => "$result->accountname\n$result->password",
			'lastlogintime' => ($casting || !$account) ? 0 : $account->getLastLogin(),
			'ispremium' => ($casting || !$account) ? true : $account->isPremium(),
			'premiumuntil' => ($casting || !$account) ? 0 : (time() + ($account->getPremDays() * 86400)),
			'status' => 'active', // active, frozen or suspended
			'returnernotification' => false,
			'showrewardnews' => true,
			'isreturner' => true,
			'fpstracking' => false,
			'optiontracking' => false,
			'tournamentticketpurchasestate' => 0,
			'emailcoderequest' => false
		];

		die(json_encode(compact('session', 'playdata')));
	break;

	default:
		sendError("Unrecognized event {$action}.");
	break;
}

function create_char($player) {
	global $config;
	return [
		'worldid' => 0,
		'name' => $player['name'],
		'ismale' => intval($player['sex']) === 1,
		'tutorial' => false, //intval($player['lastlogin']) === 0,
		'vocation' => $config['vocations'][$player['vocation']],
		'outfitid' => intval($player['looktype']),
		'headcolor' => intval($player['lookhead']),
		'torsocolor' => intval($player['lookbody']),
		'legscolor' => intval($player['looklegs']),
		'detailcolor' => intval($player['lookfeet']),
		'addonsflags' => intval($player['lookaddons']),
		'ishidden' => intval($player['deleted']) === 1,
		'istournamentparticipant' => false,
		'remainingdailytournamentplaytime' => 0
	];
}
